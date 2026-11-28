<?php

namespace App\Services;

use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Support\Collection;

/**
 * Audit trail export (v2.0.3).
 *
 * The audit trail itself stays append-only: the model cancels
 * updates and deletes, and there is no delete endpoint. The
 * export is a read of the same filtered list the audit page
 * shows, as CSV (a file download) or JSON. The only sanctioned
 * deletion path for audit rows remains the retention purge
 * (RetentionService), which writes one audit row recording the
 * counts after the deletions.
 */
class AuditExportService
{
    public const FORMAT_CSV = 'csv';

    public const FORMAT_JSON = 'json';

    /**
     * @return array<int, string>
     */
    public static function formats(): array
    {
        return [self::FORMAT_CSV, self::FORMAT_JSON];
    }

    /**
     * The filtered audit rows, newest first, with the same
     * filters as the audit list endpoint. Capped at 5,000 rows
     * so an export cannot dump an unbounded history in one
     * request; the list endpoint paginates for the rest.
     *
     * @param  array<string, string>  $filters
     * @return Collection<int, AuditLog>
     */
    public function rows(Organization $organization, array $filters): Collection
    {
        return AuditLog::forOrganization($organization->getKey())
            ->with('actor')
            ->when($filters['entity_type'] ?? null, fn ($query, $value) => $query->where('entity_type', $value))
            ->when($filters['action'] ?? null, fn ($query, $value) => $query->where('action', $value))
            ->when($filters['actor_id'] ?? null, fn ($query, $value) => $query->where('actor_id', $value))
            ->when($filters['date_from'] ?? null, fn ($query, $value) => $query->whereDate('created_at', '>=', $value))
            ->when($filters['date_to'] ?? null, fn ($query, $value) => $query->whereDate('created_at', '<=', $value))
            ->orderByDesc('created_at')
            ->limit(5000)
            ->get();
    }

    /**
     * The rows as plain API arrays, for the JSON export.
     *
     * @param  array<string, string>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function asJson(Organization $organization, array $filters): array
    {
        $request = request();

        return AuditLogResource::collection($this->rows($organization, $filters))
            ->toArray($request);
    }

    /**
     * The rows as a CSV string, one row per audit entry.
     *
     * @param  array<string, string>  $filters
     */
    public function asCsv(Organization $organization, array $filters): string
    {
        $rows = $this->rows($organization, $filters);

        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            'id', 'created_at', 'actor_type', 'actor_id', 'actor_name',
            'action', 'entity_type', 'entity_id', 'correlation_id',
            'ip_address', 'before', 'after',
        ]);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row->getKey(),
                $row->created_at?->toIso8601String(),
                $row->actor_type->value,
                $row->actor_id,
                $row->actor?->name,
                $row->action,
                $row->entity_type,
                $row->entity_id,
                $row->correlation_id,
                $row->ip_address,
                $row->before !== null ? json_encode($row->before) : null,
                $row->after !== null ? json_encode($row->after) : null,
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }
}
