<?php

namespace App\Services;

use App\Models\Carrier;
use App\Models\ImportMapping;
use App\Models\ImportRow;
use App\Models\ImportRun;
use App\Models\ImportSchedule;
use App\Models\Organization;
use App\Models\Shipment;
use App\Models\SyncRun;
use App\Models\User;
use App\NotificationType;
use App\Permission;
use App\ShipmentStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Advanced imports (v1.3.1).
 *
 * Saved CSV mappings, import history, scheduled imports,
 * synchronization workflows, row-level error correction, and
 * import notifications. Imports target shipments only.
 *
 * Uploads still go through the two-step preview/confirm flow
 * in ShipmentCsvImporter; this service records the run and its
 * rows from the importer's result. Schedules and sync runs
 * import a file the organization already uploaded, with the
 * saved mapping, through the importer's runFile. No live
 * SFTP, URL, or provider source is involved.
 */
class ImportService
{
    public function __construct(
        protected ShipmentCsvImporter $importer,
        protected AuditService $audit,
        protected NotificationService $notifications,
    ) {}

    /**
     * Disk for stored schedule and sync source files.
     */
    public static function disk(): string
    {
        return config('evidence.disk');
    }

    /**
     * The shipment fields a mapping may target.
     *
     * @return array<int, string>
     */
    public static function allowedMappingFields(): array
    {
        return ShipmentCsvImporter::allowedFields();
    }

    /**
     * Validate a mapping body: exact header text to field name.
     *
     * @param  array<string, mixed>  $mapping
     * @return array<string, string>
     */
    public static function validatedMapping(array $mapping): array
    {
        $allowed = self::allowedMappingFields();
        $clean = [];

        foreach ($mapping as $header => $field) {
            if (! is_string($header) || trim($header) === '') {
                throw ValidationException::withMessages(['mapping' => ['Mapping headers must be non-empty strings.']]);
            }

            if (! is_string($field) || ! in_array($field, $allowed, true)) {
                throw ValidationException::withMessages(['mapping' => [
                    'Field "'.$field.'" is not a shipment field a mapping may target.',
                ]]);
            }

            $clean[trim($header)] = $field;
        }

        if ($clean === []) {
            throw ValidationException::withMessages(['mapping' => ['A mapping needs at least one header.']]);
        }

        return $clean;
    }

    /**
     * Create a saved mapping. A default mapping unsets the
     * previous default for the same target.
     */
    public function createMapping(Organization $organization, User $actor, string $name, string $target, array $mapping, bool $isDefault): ImportMapping
    {
        $clean = self::validatedMapping($mapping);

        $record = DB::transaction(function () use ($organization, $actor, $name, $target, $clean, $isDefault) {
            if ($isDefault) {
                ImportMapping::forOrganization($organization)
                    ->where('target', $target)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            return ImportMapping::create([
                'organization_id' => $organization->getKey(),
                'name' => $name,
                'target' => $target,
                'mapping' => $clean,
                'is_default' => $isDefault,
                'created_by' => $actor->getKey(),
            ]);
        });

        $this->audit->record($organization, 'imports.mapping_created', $record, $actor);

        return $record;
    }

    /**
     * Update a saved mapping. A new default unsets the previous one.
     */
    public function updateMapping(ImportMapping $mapping, User $actor, array $attributes): ImportMapping
    {
        $organization = $mapping->organization;
        $before = $mapping->only(['name', 'target', 'mapping', 'is_default']);

        DB::transaction(function () use ($mapping, $attributes) {
            if (($attributes['is_default'] ?? false) === true) {
                ImportMapping::forOrganization($mapping->organization_id)
                    ->where('target', $attributes['target'] ?? $mapping->target)
                    ->where('is_default', true)
                    ->whereKeyNot($mapping->getKey())
                    ->update(['is_default' => false]);
            }

            $mapping->update($attributes);
        });

        $this->audit->record(
            $organization,
            'imports.mapping_updated',
            $mapping->fresh(),
            $actor,
            $before,
            $mapping->fresh()->only(['name', 'target', 'mapping', 'is_default'])
        );

        return $mapping->fresh();
    }

    /**
     * Delete a saved mapping. Runs, schedules, and sync runs
     * keep working: their mapping link is nulled, and the
     * stored mapping JSON on the run stays as the record.
     */
    public function deleteMapping(ImportMapping $mapping, User $actor): void
    {
        $organization = $mapping->organization;

        $mapping->delete();

        $this->audit->record(
            $organization,
            'imports.mapping_deleted',
            $mapping,
            $actor,
            ['name' => $mapping->name]
        );
    }

    /**
     * Record an import run and its rows from an importer result.
     *
     * The result is the confirm/runFile shape: imported, skipped,
     * skipped_rows, rows (each with index, data, errors,
     * is_duplicate, status, shipment_id), and filename. Counts
     * are derived from the rows, so a later correction can
     * refresh them from the same source of truth.
     */
    public function recordRun(
        Organization $organization,
        User $actor,
        string $source,
        array $result,
        ?string $mappingId = null,
        ?string $scheduleId = null,
        ?string $syncRunId = null,
    ): ImportRun {
        $rows = $result['rows'] ?? [];

        $run = DB::transaction(function () use ($organization, $actor, $source, $result, $rows, $mappingId, $scheduleId, $syncRunId) {
            $run = ImportRun::create([
                'organization_id' => $organization->getKey(),
                'source' => $source,
                'import_mapping_id' => $mappingId,
                'import_schedule_id' => $scheduleId,
                'sync_run_id' => $syncRunId,
                'filename' => $result['filename'] ?? null,
                'status' => ImportRun::STATUS_PENDING,
                'total_rows' => count($rows),
                'summary' => $result['summary'] ?? null,
                'started_at' => now(),
                'finished_at' => now(),
                'created_by' => $actor->getKey(),
            ]);

            foreach ($rows as $row) {
                ImportRow::create([
                    'organization_id' => $organization->getKey(),
                    'import_run_id' => $run->getKey(),
                    'row_index' => $row['index'],
                    'data' => $row['data'],
                    'status' => $row['status'],
                    'errors' => $row['errors'] ?: null,
                    'shipment_id' => $row['shipment_id'] ?? null,
                ]);
            }

            $this->refreshRunCounts($run);

            return $run->fresh();
        });

        $this->notifyRun($run, $organization, $actor);

        return $run;
    }

    /**
     * Record a run that never parsed: the file was missing or
     * unreadable, or the CSV itself was rejected.
     */
    public function recordFailedRun(
        Organization $organization,
        User $actor,
        string $source,
        string $errorMessage,
        ?string $filename = null,
        ?string $mappingId = null,
        ?string $scheduleId = null,
        ?string $syncRunId = null,
    ): ImportRun {
        $run = ImportRun::create([
            'organization_id' => $organization->getKey(),
            'source' => $source,
            'import_mapping_id' => $mappingId,
            'import_schedule_id' => $scheduleId,
            'sync_run_id' => $syncRunId,
            'filename' => $filename,
            'status' => ImportRun::STATUS_FAILED,
            'total_rows' => 0,
            'error_message' => $errorMessage,
            'started_at' => now(),
            'finished_at' => now(),
            'created_by' => $actor->getKey(),
        ]);

        $this->notifyRun($run, $organization, $actor);

        return $run;
    }

    /**
     * Recompute a run's counts and status from its rows.
     */
    public function refreshRunCounts(ImportRun $run): ImportRun
    {
        $rows = $run->rows()->get();

        $imported = $rows->whereIn('status', [ImportRow::STATUS_IMPORTED, ImportRow::STATUS_CORRECTED])->count();
        $duplicates = $rows->where('status', ImportRow::STATUS_DUPLICATE)->count();
        $errors = $rows->whereIn('status', [ImportRow::STATUS_SKIPPED, ImportRow::STATUS_FAILED])->count();

        $run->update([
            'total_rows' => $rows->count(),
            'imported_rows' => $imported,
            'skipped_rows' => $duplicates + $errors,
            'duplicate_rows' => $duplicates,
            'error_rows' => $errors,
            'status' => $rows->isEmpty()
                ? ImportRun::STATUS_FAILED
                : ($imported === $rows->count()
                    ? ImportRun::STATUS_COMPLETED
                    : ($imported > 0
                        ? ImportRun::STATUS_PARTIAL
                        : ImportRun::STATUS_FAILED)),
        ]);

        return $run->fresh();
    }

    /**
     * Run a stored file with a mapping and record the run.
     *
     * A file that cannot be read records a failed run instead
     * of throwing, so the history shows the attempt.
     */
    public function runStoredFile(
        Organization $organization,
        User $actor,
        string $sourcePath,
        array $mapping,
        string $source,
        ?string $filename = null,
        ?string $mappingId = null,
        ?string $scheduleId = null,
        ?string $syncRunId = null,
    ): ImportRun {
        $disk = Storage::disk(self::disk());

        if (! $disk->exists($sourcePath)) {
            return $this->recordFailedRun(
                $organization,
                $actor,
                $source,
                'The source file is no longer stored. Upload it again.',
                $filename,
                $mappingId,
                $scheduleId,
                $syncRunId,
            );
        }

        try {
            $result = $this->importer->runFile(
                $disk->path($sourcePath),
                (string) $organization->getKey(),
                $mapping,
                (int) $actor->getKey(),
                $filename,
            );
        } catch (ValidationException $exception) {
            return $this->recordFailedRun(
                $organization,
                $actor,
                $source,
                collect($exception->errors())->flatten()->first() ?? 'The file could not be imported.',
                $filename,
                $mappingId,
                $scheduleId,
                $syncRunId,
            );
        }

        return $this->recordRun(
            $organization,
            $actor,
            $source,
            $result,
            $mappingId,
            $scheduleId,
            $syncRunId,
        );
    }

    /**
     * Correct one failed or duplicate row and retry it.
     *
     * The corrected field values merge over the stored row data.
     * The merged row is validated with the same rules the
     * importer uses, and the reference is re-checked against
     * the database. A valid row creates the shipment, the row
     * becomes corrected, and the run counts refresh.
     *
     * @param  array<string, mixed>  $corrected
     */
    public function correctRow(ImportRun $run, ImportRow $row, User $actor, array $corrected): ImportRow
    {
        if (! $row->isCorrectable()) {
            throw ValidationException::withMessages(['row' => [
                'This row cannot be corrected: it already imported.',
            ]]);
        }

        $organization = $run->organization;
        $data = array_merge($row->data ?? [], $corrected);

        // A carrier name in the correction resolves the same way
        // the importer resolves it; anything else is a shipment
        // field as stored.
        $errors = [];

        if (isset($data['carrier']) && is_string($data['carrier']) && trim($data['carrier']) !== '') {
            $carrier = Carrier::forOrganization($organization)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['carrier']))])
                ->first();

            if ($carrier === null) {
                $errors['carrier'] = ['No carrier named "'.$data['carrier'].'" exists in this organization.'];
            } else {
                $data['carrier_id'] = $carrier->getKey();
            }

            unset($data['carrier']);
        }

        $rules = ShipmentRules::createRules((string) $organization->getKey(), false);
        $rules['status'] = ['nullable', 'string', Rule::in([
            ShipmentStatus::Draft->value,
            ShipmentStatus::Planned->value,
            ShipmentStatus::InTransit->value,
            ShipmentStatus::Delivered->value,
            ShipmentStatus::Completed->value,
        ])];

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            $errors = array_merge($errors, $validator->errors()->toArray());
        }

        $reference = $data['internal_reference'] ?? null;

        if ($reference !== null) {
            $taken = Shipment::forOrganization($organization)
                ->where('internal_reference', $reference)
                ->exists();

            if ($taken) {
                $errors['internal_reference'] = ['A shipment with this reference already exists.'];
            }
        }

        if ($errors !== []) {
            // The correction is stored with its new errors, so
            // the next attempt starts from the latest values.
            $row->update([
                'data' => $data,
                'errors' => $errors,
            ]);

            throw ValidationException::withMessages($errors);
        }

        $before = $row->only(['status', 'data', 'errors']);

        $shipment = DB::transaction(function () use ($row, $data, $organization, $actor) {
            $data['organization_id'] = $organization->getKey();
            $data['created_by'] = $actor->getKey();
            $data['status'] = $data['status'] ?? ShipmentStatus::Draft->value;

            $shipment = Shipment::create($data);

            $row->update([
                'data' => $data,
                'status' => ImportRow::STATUS_CORRECTED,
                'errors' => null,
                'shipment_id' => $shipment->getKey(),
                'corrected_at' => now(),
                'corrected_by' => $actor->getKey(),
            ]);

            return $shipment;
        });

        $this->refreshRunCounts($run);

        $this->audit->record(
            $organization,
            'imports.row_corrected',
            $row->fresh(),
            $actor,
            $before,
            ['status' => ImportRow::STATUS_CORRECTED, 'shipment_id' => (string) $shipment->getKey()]
        );

        return $row->fresh();
    }

    /**
     * Run one schedule now, from its stored file and mapping.
     */
    public function runSchedule(ImportSchedule $schedule, User $actor): ImportRun
    {
        $organization = $schedule->organization;
        $mapping = $schedule->mapping?->mapping ?? [];

        $run = $this->runStoredFile(
            $organization,
            $actor,
            $schedule->source_file_path,
            $mapping,
            ImportRun::SOURCE_SCHEDULE,
            basename($schedule->source_file_path),
            $schedule->import_mapping_id,
            (string) $schedule->getKey(),
        );

        $schedule->update([
            'last_run_at' => now(),
            'last_import_run_id' => $run->getKey(),
            'next_run_at' => $schedule->nextRunAfterNow(),
        ]);

        $this->audit->record(
            $organization,
            'imports.schedule_run',
            $schedule->fresh(),
            $actor,
            null,
            ['import_run_id' => (string) $run->getKey(), 'status' => $run->status]
        );

        return $run;
    }

    /**
     * Run one synchronization workflow, through its lifecycle.
     */
    public function runSync(SyncRun $syncRun, User $actor): SyncRun
    {
        $organization = $syncRun->organization;
        $mapping = $syncRun->mapping?->mapping ?? [];

        $syncRun->update([
            'status' => SyncRun::STATUS_RUNNING,
            'started_at' => now(),
            'finished_at' => null,
            'error_message' => null,
        ]);

        $run = $this->runStoredFile(
            $organization,
            $actor,
            $syncRun->source_file_path,
            $mapping,
            ImportRun::SOURCE_SYNC,
            basename($syncRun->source_file_path),
            $syncRun->import_mapping_id,
            null,
            (string) $syncRun->getKey(),
        );

        $syncRun->update([
            'status' => $run->status === ImportRun::STATUS_FAILED
                ? SyncRun::STATUS_FAILED
                : SyncRun::STATUS_COMPLETED,
            'import_run_id' => $run->getKey(),
            'summary' => [
                'total' => $run->total_rows,
                'imported' => $run->imported_rows,
                'skipped' => $run->skipped_rows,
                'duplicate' => $run->duplicate_rows,
            ],
            'error_message' => $run->error_message,
            'finished_at' => now(),
        ]);

        $this->audit->record(
            $organization,
            'imports.sync_run',
            $syncRun->fresh(),
            $actor,
            null,
            ['import_run_id' => (string) $run->getKey(), 'status' => $syncRun->status]
        );

        return $syncRun->fresh();
    }

    /**
     * API payload for a saved mapping.
     *
     * @return array<string, mixed>
     */
    public function mappingPayload(ImportMapping $mapping): array
    {
        return [
            'id' => (string) $mapping->getKey(),
            'name' => $mapping->name,
            'target' => $mapping->target,
            'mapping' => $mapping->mapping,
            'is_default' => $mapping->is_default,
            'created_at' => $mapping->created_at?->toIso8601String(),
            'updated_at' => $mapping->updated_at?->toIso8601String(),
        ];
    }

    /**
     * API payload for an import run, with its outcome counts.
     *
     * @return array<string, mixed>
     */
    public function runPayload(ImportRun $run): array
    {
        return [
            'id' => (string) $run->getKey(),
            'source' => $run->source,
            'import_mapping_id' => $run->import_mapping_id !== null ? (string) $run->import_mapping_id : null,
            'import_schedule_id' => $run->import_schedule_id !== null ? (string) $run->import_schedule_id : null,
            'sync_run_id' => $run->sync_run_id !== null ? (string) $run->sync_run_id : null,
            'filename' => $run->filename,
            'status' => $run->status,
            'total_rows' => $run->total_rows,
            'imported_rows' => $run->imported_rows,
            'skipped_rows' => $run->skipped_rows,
            'duplicate_rows' => $run->duplicate_rows,
            'error_rows' => $run->error_rows,
            'summary' => $run->summary,
            'error_message' => $run->error_message,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }

    /**
     * API payload for one import row.
     *
     * @return array<string, mixed>
     */
    public function rowPayload(ImportRow $row): array
    {
        return [
            'id' => (string) $row->getKey(),
            'import_run_id' => (string) $row->import_run_id,
            'row_index' => $row->row_index,
            'data' => $row->data,
            'status' => $row->status,
            'errors' => $row->errors,
            'shipment_id' => $row->shipment_id !== null ? (string) $row->shipment_id : null,
            'corrected_at' => $row->corrected_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    /**
     * API payload for a schedule.
     *
     * @return array<string, mixed>
     */
    public function schedulePayload(ImportSchedule $schedule): array
    {
        return [
            'id' => (string) $schedule->getKey(),
            'name' => $schedule->name,
            'import_mapping_id' => $schedule->import_mapping_id !== null ? (string) $schedule->import_mapping_id : null,
            'frequency' => $schedule->frequency,
            'next_run_at' => $schedule->next_run_at?->toIso8601String(),
            'last_run_at' => $schedule->last_run_at?->toIso8601String(),
            'last_import_run_id' => $schedule->last_import_run_id !== null ? (string) $schedule->last_import_run_id : null,
            'active' => $schedule->active,
            'created_at' => $schedule->created_at?->toIso8601String(),
            'updated_at' => $schedule->updated_at?->toIso8601String(),
        ];
    }

    /**
     * API payload for a synchronization workflow.
     *
     * @return array<string, mixed>
     */
    public function syncPayload(SyncRun $syncRun): array
    {
        return [
            'id' => (string) $syncRun->getKey(),
            'name' => $syncRun->name,
            'import_mapping_id' => $syncRun->import_mapping_id !== null ? (string) $syncRun->import_mapping_id : null,
            'status' => $syncRun->status,
            'import_run_id' => $syncRun->import_run_id !== null ? (string) $syncRun->import_run_id : null,
            'summary' => $syncRun->summary,
            'error_message' => $syncRun->error_message,
            'started_at' => $syncRun->started_at?->toIso8601String(),
            'finished_at' => $syncRun->finished_at?->toIso8601String(),
            'created_at' => $syncRun->created_at?->toIso8601String(),
            'updated_at' => $syncRun->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Notify members who can manage imports when a run finishes.
     */
    public function notifyRun(ImportRun $run, Organization $organization, User $actor): void
    {
        $failed = $run->status === ImportRun::STATUS_FAILED;

        $members = $this->notifications->membersWithPermission(
            $organization,
            Permission::ManageImports,
            $actor,
        );

        $this->notifications->notify(
            $organization,
            $members,
            $failed ? NotificationType::ImportFailed : NotificationType::ImportCompleted,
            $failed
                ? 'Import failed'
                : 'Import finished: '.$run->imported_rows.' imported, '.$run->skipped_rows.' skipped',
            $failed
                ? ($run->error_message ?? 'The import could not run.')
                : 'File '.($run->filename ?? 'import').' finished with '.$run->imported_rows.' imported, '.$run->duplicate_rows.' duplicates, and '.$run->error_rows.' rows with errors.',
            ['import_run_id' => (string) $run->getKey()],
            'import-run:'.$run->getKey(),
            $actor,
        );
    }
}
