<?php

namespace App\Services;

use App\Models\EvidenceFile;
use App\Models\Organization;
use App\Models\Shipment;
use App\Models\TmsIntegration;
use App\Models\TmsSyncRun;
use App\Models\User;
use App\NotificationType;
use App\Permission;
use App\ShipmentStatus;
use App\Tms\TmsProvider;
use App\Tms\TmsProviderException;
use App\Tms\TmsProviderRegistry;
use App\Tms\TrimbleTmwMapper;
use App\Tms\TrimbleTmwProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * TMS integrations (v1.3.2).
 *
 * A generic provider adapter plus a mock provider, with the
 * first real provider (Trimble TMW.Suite) modelled against
 * its public docs and schema-only: no live TMS call is made
 * anywhere. Syncs apply provider records to shipments
 * (create or update by external reference, dedupe on
 * re-run), shipment statuses (forward transitions only),
 * appointments, and documents (stored as evidence files).
 * Every run is recorded as a TmsSyncRun with per-outcome
 * counts, and members who can manage integrations are
 * notified when a run finishes or fails.
 */
class TmsService
{
    public function __construct(
        protected AuditService $audit,
        protected NotificationService $notifications,
    ) {}

    /**
     * Disk for stored TMS-synced documents (the evidence disk).
     */
    public static function disk(): string
    {
        return config('evidence.disk');
    }

    /**
     * The provider catalogue, for the API and the frontend.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function providers(): array
    {
        return TmsProviderRegistry::catalogue();
    }

    /**
     * Validate and normalize an integration settings body.
     *
     * @param  array<string, mixed>  $settings
     * @return array{sync_shipments: bool, sync_status: bool, sync_appointments: bool, sync_documents: bool, auto_sync: bool, sync_interval_hours: int}
     */
    public static function validatedSettings(array $settings): array
    {
        $clean = [
            'sync_shipments' => (bool) ($settings['sync_shipments'] ?? true),
            'sync_status' => (bool) ($settings['sync_status'] ?? true),
            'sync_appointments' => (bool) ($settings['sync_appointments'] ?? true),
            'sync_documents' => (bool) ($settings['sync_documents'] ?? true),
            'auto_sync' => (bool) ($settings['auto_sync'] ?? false),
            'sync_interval_hours' => (int) ($settings['sync_interval_hours'] ?? 24),
        ];

        $clean['sync_interval_hours'] = max(1, min(168, $clean['sync_interval_hours']));

        return $clean;
    }

    /**
     * Validate a credentials body for a provider. The mock
     * needs none; Trimble needs a client id, client secret,
     * and account id.
     *
     * @param  array<string, mixed>  $credentials
     */
    public static function validatedCredentials(string $provider, array $credentials): array
    {
        if ($provider === 'trimble-tmw') {
            $missing = [];

            foreach (TrimbleTmwProvider::requiredCredentialFields() as $field) {
                if (! isset($credentials[$field]) || trim((string) $credentials[$field]) === '') {
                    $missing[] = $field;
                }
            }

            if ($missing !== []) {
                throw ValidationException::withMessages(['credentials' => [
                    'Trimble credentials need: '.implode(', ', $missing).'.',
                ]]);
            }
        }

        return $credentials;
    }

    /**
     * The API payload for one integration. Credentials are
     * never returned: the payload says only whether any are
     * stored, and for Trimble which fields are present.
     *
     * @return array<string, mixed>
     */
    public function integrationPayload(TmsIntegration $integration): array
    {
        $provider = TmsProviderRegistry::resolve($integration->provider);
        $credentials = $integration->credentialsArray();

        return [
            'id' => (string) $integration->getKey(),
            'provider' => $integration->provider,
            'provider_label' => $provider->label(),
            'live_available' => $provider->liveAvailable(),
            'name' => $integration->name,
            'status' => $integration->status,
            'settings' => $integration->effectiveSettings(),
            'has_credentials' => $credentials !== [],
            'credential_fields_present' => array_keys($credentials),
            'last_sync_at' => $integration->last_sync_at?->toIso8601String(),
            'last_error' => $integration->last_error,
            'created_at' => $integration->created_at?->toIso8601String(),
            'updated_at' => $integration->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The API payload for one sync run.
     *
     * @return array<string, mixed>
     */
    public function syncRunPayload(TmsSyncRun $run): array
    {
        return [
            'id' => (string) $run->getKey(),
            'tms_integration_id' => (string) $run->tms_integration_id,
            'kind' => $run->kind,
            'status' => $run->status,
            'summary' => $run->summary,
            'error_message' => $run->error_message,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
            'updated_at' => $run->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Create an integration. Names are unique per organization.
     */
    public function createIntegration(
        Organization $organization,
        User $actor,
        string $provider,
        string $name,
        array $credentials,
        array $settings,
    ): TmsIntegration {
        if (! TmsProviderRegistry::has($provider)) {
            throw ValidationException::withMessages(['provider' => [
                'Unknown TMS provider "'.$provider.'".',
            ]]);
        }

        $credentials = self::validatedCredentials($provider, $credentials);
        $settings = self::validatedSettings($settings);

        $integration = DB::transaction(fn () => TmsIntegration::create([
            'organization_id' => $organization->getKey(),
            'provider' => $provider,
            'name' => $name,
            'status' => TmsIntegration::STATUS_ACTIVE,
            'credentials' => $credentials,
            'settings' => $settings,
            'created_by' => $actor->getKey(),
        ]));

        $this->audit->record($organization, 'tms.integration_created', $integration, $actor);

        return $integration;
    }

    /**
     * Update an integration. Credentials are only replaced
     * when supplied; settings merge over the stored ones.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateIntegration(TmsIntegration $integration, User $actor, array $data): TmsIntegration
    {
        $organization = $integration->organization;

        if (array_key_exists('credentials', $data)) {
            $integration->credentials = self::validatedCredentials(
                $integration->provider,
                is_array($data['credentials']) ? $data['credentials'] : []
            );
        }

        if (array_key_exists('settings', $data) && is_array($data['settings'])) {
            $integration->settings = self::validatedSettings(
                array_merge($integration->effectiveSettings(), $data['settings'])
            );
        }

        if (array_key_exists('name', $data)) {
            $integration->name = $data['name'];
        }

        if (array_key_exists('status', $data)) {
            $integration->status = $data['status'];
        }

        $integration->save();

        $this->audit->record($organization, 'tms.integration_updated', $integration, $actor);

        return $integration->refresh();
    }

    /**
     * Delete an integration. Its sync runs go with it
     * (cascade); shipments and evidence created by syncs stay.
     */
    public function deleteIntegration(TmsIntegration $integration, User $actor): void
    {
        $organization = $integration->organization;

        $integration->delete();

        $this->audit->record($organization, 'tms.integration_deleted', $integration, $actor);
    }

    /**
     * Test the connection: authenticate against the provider.
     * No sync runs, no live call for a schema-only provider.
     *
     * @return array{ok: bool, live_available: bool, message: string, token: string|null, expires_at: string|null}
     */
    public function testConnection(TmsIntegration $integration): array
    {
        $provider = TmsProviderRegistry::resolve($integration->provider);

        return $provider->authenticate($integration->credentialsArray());
    }

    /**
     * Run a sync now and record it as a TmsSyncRun.
     * kind is shipments, status, appointments, documents, or
     * full. A full sync runs only the kinds the integration
     * settings enable; an explicit kind runs that kind
     * regardless of settings. A disabled integration, or one
     * with nothing enabled, fails fast with a plain message.
     */
    public function runSync(TmsIntegration $integration, string $kind, ?User $actor): TmsSyncRun
    {
        $organization = $integration->organization;

        $run = TmsSyncRun::create([
            'organization_id' => $organization->getKey(),
            'tms_integration_id' => $integration->getKey(),
            'kind' => $kind,
            'status' => TmsSyncRun::STATUS_RUNNING,
            'started_at' => now(),
            'created_by' => $actor?->getKey(),
        ]);

        try {
            if ($integration->status !== TmsIntegration::STATUS_ACTIVE) {
                throw new TmsProviderException(
                    TmsProviderException::CODE_PROVIDER_ERROR,
                    'Integration "'.$integration->name.'" is disabled. Enable it before syncing.'
                );
            }

            $provider = TmsProviderRegistry::resolve($integration->provider);
            $auth = $provider->authenticate($integration->credentialsArray());

            if (! $auth['ok']) {
                throw new TmsProviderException(
                    TmsProviderException::CODE_INVALID_CREDENTIALS,
                    $auth['message']
                );
            }

            $kinds = $kind === TmsSyncRun::KIND_FULL
                ? $this->enabledKinds($integration)
                : [$kind];

            if ($kinds === []) {
                throw new TmsProviderException(
                    TmsProviderException::CODE_PROVIDER_ERROR,
                    'Integration "'.$integration->name.'" has no sync kind enabled. Enable at least one kind in its settings.'
                );
            }

            $summary = $this->applyKinds($provider, $integration, $organization, $actor, $kinds);

            $run->update([
                'status' => TmsSyncRun::STATUS_COMPLETED,
                'summary' => $summary,
                'finished_at' => now(),
            ]);

            $integration->update([
                'last_sync_at' => now(),
                'last_error' => null,
            ]);

            $this->audit->record($organization, 'tms.sync_completed', $run, $actor);
            $this->notifySync($run, $organization, $actor);
        } catch (TmsProviderException $exception) {
            $this->failRun($run, $integration, $organization, $actor, $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->failRun($run, $integration, $organization, $actor, 'Sync failed: '.$exception->getMessage());
        }

        return $run->refresh();
    }

    /**
     * The kinds a full sync runs: the enabled ones, in
     * checklist order.
     *
     * @return array<int, string>
     */
    protected function enabledKinds(TmsIntegration $integration): array
    {
        $settings = $integration->effectiveSettings();
        $kinds = [];

        if ($settings['sync_shipments']) {
            $kinds[] = TmsSyncRun::KIND_SHIPMENTS;
        }
        if ($settings['sync_status']) {
            $kinds[] = TmsSyncRun::KIND_STATUS;
        }
        if ($settings['sync_appointments']) {
            $kinds[] = TmsSyncRun::KIND_APPOINTMENTS;
        }
        if ($settings['sync_documents']) {
            $kinds[] = TmsSyncRun::KIND_DOCUMENTS;
        }

        return $kinds;
    }

    /**
     * Apply each requested kind and collect the summary.
     * The provider shipment list is fetched once and shared:
     * every kind works from the same loads.
     *
     * @param  array<int, string>  $kinds
     * @return array<string, mixed>
     */
    protected function applyKinds(
        TmsProvider $provider,
        TmsIntegration $integration,
        Organization $organization,
        ?User $actor,
        array $kinds,
    ): array {
        $credentials = $integration->credentialsArray();
        $loads = $provider->fetchShipments($credentials);

        $summary = [
            'fetched' => count($loads),
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'documents_created' => 0,
            'kinds' => [],
        ];

        foreach ($kinds as $kind) {
            $counts = match ($kind) {
                TmsSyncRun::KIND_SHIPMENTS => $this->applyShipments($organization, $actor, $loads),
                TmsSyncRun::KIND_STATUS => $this->applyStatuses($organization, $loads),
                TmsSyncRun::KIND_APPOINTMENTS => $this->applyAppointments($provider, $credentials, $organization, $loads),
                TmsSyncRun::KIND_DOCUMENTS => $this->applyDocuments($provider, $credentials, $integration, $organization, $actor, $loads),
                default => ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0],
            };

            $summary['kinds'][$kind] = $counts;

            foreach (['created', 'updated', 'skipped', 'failed'] as $key) {
                $summary[$key] += $counts[$key] ?? 0;
            }

            $summary['documents_created'] += $counts['documents_created'] ?? 0;
        }

        return $summary;
    }

    /**
     * Apply shipment records: create missing shipments,
     * update existing ones. Matches by external reference
     * first, then by internal reference. Status changes
     * follow the forward-only transition rule.
     *
     * @param  array<int, array<string, mixed>>  $loads
     * @return array<string, int>
     */
    protected function applyShipments(Organization $organization, ?User $actor, array $loads): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($loads as $load) {
            $external = (string) ($load['external_reference'] ?? '');

            if ($external === '') {
                $counts['failed']++;

                continue;
            }

            $shipment = $this->findShipment($organization, $load);
            $attributes = TrimbleTmwMapper::toShipmentAttributes($load);
            $status = isset($load['status'])
                ? TrimbleTmwMapper::toShipmentStatus((string) $load['status'])
                : null;

            if ($shipment === null) {
                $attributes['organization_id'] = $organization->getKey();
                $attributes['status'] = $status ?? ShipmentStatus::Draft;
                $attributes['created_by'] = $actor?->getKey();

                Shipment::create($attributes);
                $counts['created']++;

                continue;
            }

            $shipment->fill($attributes);
            $this->applyStatus($shipment, $status);

            if ($shipment->isDirty()) {
                $shipment->save();
                $counts['updated']++;
            } else {
                $counts['skipped']++;
            }
        }

        return $counts;
    }

    /**
     * Apply status records: update the shipment status
     * where the transition is allowed. Shipments the
     * provider knows but the app does not are skipped, not
     * created: status sync never creates shipments.
     *
     * @param  array<int, array<string, mixed>>  $loads
     * @return array<string, int>
     */
    protected function applyStatuses(Organization $organization, array $loads): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($loads as $load) {
            $shipment = $this->findShipment($organization, $load);

            if ($shipment === null) {
                $counts['skipped']++;

                continue;
            }

            $status = isset($load['status'])
                ? TrimbleTmwMapper::toShipmentStatus((string) $load['status'])
                : null;

            if ($status === null) {
                $counts['skipped']++;

                continue;
            }

            if ($shipment->status === $status) {
                $counts['skipped']++;

                continue;
            }

            if (! $shipment->status->canTransitionTo($status)) {
                $counts['skipped']++;

                continue;
            }

            $shipment->status = $status;
            $shipment->save();
            $counts['updated']++;
        }

        return $counts;
    }

    /**
     * Apply appointments: update the appointment and
     * actual times of matched shipments.
     *
     * @param  array<int, array<string, mixed>>  $loads
     * @return array<string, int>
     */
    protected function applyAppointments(
        TmsProvider $provider,
        array $credentials,
        Organization $organization,
        array $loads,
    ): array {
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($loads as $load) {
            $shipment = $this->findShipment($organization, $load);

            if ($shipment === null) {
                $counts['skipped']++;

                continue;
            }

            $appointments = $provider->fetchAppointments(
                $credentials,
                (string) $load['external_reference']
            );

            if ($appointments === []) {
                $counts['skipped']++;

                continue;
            }

            $first = $appointments[0];

            $shipment->fill(array_filter([
                'pickup_appointment' => $first['pickup_appointment'] ?? null,
                'delivery_appointment' => $first['delivery_appointment'] ?? null,
                'actual_arrival_at' => $first['actual_arrival_at'] ?? null,
                'actual_departure_at' => $first['actual_departure_at'] ?? null,
                'pickup_location' => $first['pickup_location'] ?? null,
                'delivery_location' => $first['delivery_location'] ?? null,
            ], fn ($value) => $value !== null));

            if ($shipment->isDirty()) {
                $shipment->save();
                $counts['updated']++;
            } else {
                $counts['skipped']++;
            }
        }

        return $counts;
    }

    /**
     * Apply documents: store each provider document as an
     * evidence file on the matched shipment. A document
     * with the same filename already on the shipment is
     * skipped, so re-running a sync does not duplicate
     * evidence. Shipments the app does not know are skipped.
     *
     * @param  array<int, array<string, mixed>>  $loads
     * @return array<string, int>
     */
    protected function applyDocuments(
        TmsProvider $provider,
        array $credentials,
        TmsIntegration $integration,
        Organization $organization,
        ?User $actor,
        array $loads,
    ): array {
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'documents_created' => 0];

        foreach ($loads as $load) {
            $shipment = $this->findShipment($organization, $load);

            if ($shipment === null) {
                $counts['skipped']++;

                continue;
            }

            $documents = $provider->fetchDocuments(
                $credentials,
                (string) $load['external_reference']
            );

            foreach ($documents as $document) {
                $filename = (string) ($document['filename'] ?? '');

                if ($filename === '' || ! isset($document['content'])) {
                    $counts['failed']++;

                    continue;
                }

                $exists = EvidenceFile::forOrganization($organization)
                    ->where('shipment_id', $shipment->getKey())
                    ->where('original_filename', $filename)
                    ->exists();

                if ($exists) {
                    $counts['skipped']++;

                    continue;
                }

                $content = (string) $document['content'];
                $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($filename)) ?? 'document';
                $key = 'tms/'.$organization->getKey().'/'.$integration->getKey().'/'.($document['external_id'] ?? 'doc').'-'.$safeName;

                Storage::disk(self::disk())->put($key, $content);

                EvidenceFile::create([
                    'organization_id' => $organization->getKey(),
                    'shipment_id' => $shipment->getKey(),
                    'document_category' => TrimbleTmwMapper::toEvidenceCategory((string) ($document['type'] ?? 'other')),
                    'original_filename' => $filename,
                    'storage_key' => $key,
                    'disk' => self::disk(),
                    'mime_type' => (string) ($document['mime_type'] ?? 'application/octet-stream'),
                    'file_size' => strlen($content),
                    'checksum' => hash('sha256', $content),
                    'uploaded_by' => $actor?->getKey(),
                    'description' => 'Synced from '.$integration->provider.' ('.$integration->name.').',
                ]);

                $counts['documents_created']++;
            }
        }

        return $counts;
    }

    /**
     * Apply a provider status to a shipment in place,
     * following the forward-only transition rule. A blocked
     * transition leaves the status alone; the caller counts
     * the record by whether anything changed.
     */
    protected function applyStatus(Shipment $shipment, ?ShipmentStatus $status): void
    {
        if ($status === null || $shipment->status === $status) {
            return;
        }

        if (! $shipment->status->canTransitionTo($status)) {
            return;
        }

        $shipment->status = $status;
    }

    /**
     * Find the shipment a provider record belongs to:
     * external reference first, then internal reference.
     *
     * @param  array<string, mixed>  $load
     */
    protected function findShipment(Organization $organization, array $load): ?Shipment
    {
        $external = (string) ($load['external_reference'] ?? '');
        $internal = (string) ($load['internal_reference'] ?? '');

        if ($external !== '') {
            $shipment = Shipment::forOrganization($organization)
                ->where('external_load_reference', $external)
                ->first();

            if ($shipment !== null) {
                return $shipment;
            }
        }

        if ($internal !== '') {
            return Shipment::forOrganization($organization)
                ->where('internal_reference', $internal)
                ->first();
        }

        return null;
    }

    /**
     * Mark a run failed, record the failure on the
     * integration, audit it, and notify.
     */
    protected function failRun(
        TmsSyncRun $run,
        TmsIntegration $integration,
        Organization $organization,
        ?User $actor,
        string $message,
    ): void {
        $run->update([
            'status' => TmsSyncRun::STATUS_FAILED,
            'error_message' => $message,
            'finished_at' => now(),
        ]);

        $integration->update([
            'last_sync_at' => now(),
            'last_error' => $message,
        ]);

        $this->audit->record($organization, 'tms.sync_failed', $run, $actor);
        $this->notifySync($run->refresh(), $organization, $actor);
    }

    /**
     * Notify members who can manage integrations when a
     * sync run finishes.
     */
    public function notifySync(TmsSyncRun $run, Organization $organization, ?User $actor): void
    {
        $failed = $run->status === TmsSyncRun::STATUS_FAILED;
        $summary = $run->summary ?? [];

        $members = $this->notifications->membersWithPermission(
            $organization,
            Permission::ManageTmsIntegrations,
            $actor,
        );

        $this->notifications->notify(
            $organization,
            $members,
            $failed ? NotificationType::TmsSyncFailed : NotificationType::TmsSyncCompleted,
            $failed
                ? 'TMS sync failed'
                : 'TMS sync finished: '.($summary['created'] ?? 0).' created, '.($summary['updated'] ?? 0).' updated',
            $failed
                ? ($run->error_message ?? 'The TMS sync could not run.')
                : 'Sync of kind '.$run->kind.' finished with '.($summary['fetched'] ?? 0).' loads fetched, '.($summary['created'] ?? 0).' created, '.($summary['updated'] ?? 0).' updated, '.($summary['skipped'] ?? 0).' skipped, and '.($summary['documents_created'] ?? 0).' documents stored.',
            ['tms_sync_run_id' => (string) $run->getKey()],
            'tms-sync-run:'.$run->getKey(),
            $actor,
        );
    }
}
