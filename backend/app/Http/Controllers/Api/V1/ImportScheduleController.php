<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ImportMapping;
use App\Models\ImportSchedule;
use App\Models\Organization;
use App\Permission;
use App\Services\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Scheduled imports for one organization (v1.3.1).
 *
 * A schedule re-runs a file the organization already uploaded
 * with a saved mapping. The file is stored on the private disk
 * when the schedule is created; no live SFTP, URL, or provider
 * source is involved. Frequencies: daily, weekly, manual
 * (on-demand only). Creating, updating, deleting, and running
 * need manage-imports (owner, admin, operations manager,
 * dispatcher). Any member may list and read schedules.
 */
class ImportScheduleController extends Controller
{
    public function __construct(
        protected ImportService $imports,
    ) {}

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    /**
     * The organization's schedules, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $schedules = ImportSchedule::forOrganization($organization)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $schedules->map(fn (ImportSchedule $schedule) => $this->imports->schedulePayload($schedule))->all(),
        ]);
    }

    /**
     * Create a schedule with its source file.
     */
    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageImports->value, $organization);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'import_mapping_id' => ['nullable', 'uuid', Rule::exists('import_mappings', 'id')->where('organization_id', $organization->getKey())],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'frequency' => ['sometimes', 'string', Rule::in([
                ImportSchedule::FREQUENCY_DAILY,
                ImportSchedule::FREQUENCY_WEEKLY,
                ImportSchedule::FREQUENCY_MANUAL,
            ])],
            'next_run_at' => ['nullable', 'date'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $mapping = isset($validated['import_mapping_id'])
            ? ImportMapping::forOrganization($organization)->findOrFail($validated['import_mapping_id'])
            : null;

        $frequency = $validated['frequency'] ?? ImportSchedule::FREQUENCY_MANUAL;
        $nextRunAt = $validated['next_run_at'] ?? null;

        if ($frequency === ImportSchedule::FREQUENCY_MANUAL) {
            $nextRunAt = null;
        } elseif ($nextRunAt === null) {
            $nextRunAt = $frequency === ImportSchedule::FREQUENCY_DAILY
                ? now()->addDay()->toIso8601String()
                : now()->addWeek()->toIso8601String();
        }

        $key = 'imports/'.$organization->getKey().'/schedules/'.Str::uuid().'.csv';
        Storage::disk(ImportService::disk())->put($key, file_get_contents($request->file('file')->getRealPath()));

        $schedule = ImportSchedule::create([
            'organization_id' => $organization->getKey(),
            'name' => $validated['name'],
            'import_mapping_id' => $mapping?->getKey(),
            'source_file_path' => $key,
            'frequency' => $frequency,
            'next_run_at' => $nextRunAt,
            'active' => $validated['active'] ?? true,
            'created_by' => $request->user()->getKey(),
        ]);

        return response()->json([
            'data' => $this->imports->schedulePayload($schedule),
        ], 201);
    }

    /**
     * One schedule.
     */
    public function show(Request $request, ImportSchedule $schedule): JsonResponse
    {
        $organization = $this->organization($request);

        if ($schedule->organization_id !== $organization->getKey()) {
            abort(404);
        }

        return response()->json([
            'data' => $this->imports->schedulePayload($schedule),
        ]);
    }

    /**
     * Update a schedule. A new source file replaces the stored one.
     */
    public function update(Request $request, ImportSchedule $schedule): JsonResponse
    {
        $organization = $this->organization($request);

        if ($schedule->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageImports->value, $organization);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'import_mapping_id' => ['nullable', 'uuid', Rule::exists('import_mappings', 'id')->where('organization_id', $organization->getKey())],
            'file' => ['sometimes', 'file', 'mimes:csv,txt', 'max:5120'],
            'frequency' => ['sometimes', 'string', Rule::in([
                ImportSchedule::FREQUENCY_DAILY,
                ImportSchedule::FREQUENCY_WEEKLY,
                ImportSchedule::FREQUENCY_MANUAL,
            ])],
            'next_run_at' => ['nullable', 'date'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['file'])) {
            $oldKey = $schedule->source_file_path;
            $key = 'imports/'.$organization->getKey().'/schedules/'.Str::uuid().'.csv';
            Storage::disk(ImportService::disk())->put($key, file_get_contents($validated['file']->getRealPath()));
            $validated['source_file_path'] = $key;
            unset($validated['file']);
            Storage::disk(ImportService::disk())->delete($oldKey);
        }

        if (($validated['frequency'] ?? $schedule->frequency) === ImportSchedule::FREQUENCY_MANUAL) {
            $validated['next_run_at'] = null;
        }

        $schedule->update($validated);

        return response()->json([
            'data' => $this->imports->schedulePayload($schedule->fresh()),
        ]);
    }

    /**
     * Delete a schedule and its stored source file.
     */
    public function destroy(Request $request, ImportSchedule $schedule): JsonResponse
    {
        $organization = $this->organization($request);

        if ($schedule->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageImports->value, $organization);

        Storage::disk(ImportService::disk())->delete($schedule->source_file_path);
        $schedule->delete();

        return response()->json(['message' => 'Schedule deleted.']);
    }

    /**
     * Run a schedule now, from its stored file and mapping.
     * Returns the import run that did the work.
     */
    public function run(Request $request, ImportSchedule $schedule): JsonResponse
    {
        $organization = $this->organization($request);

        if ($schedule->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageImports->value, $organization);

        $run = $this->imports->runSchedule($schedule, $request->user());

        return response()->json([
            'data' => $this->imports->runPayload($run),
        ]);
    }
}
