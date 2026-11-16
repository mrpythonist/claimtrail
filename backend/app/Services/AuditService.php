<?php

namespace App\Services;

use App\AuditActorType;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Support\Correlation;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes append-only audit rows.
 *
 * Observers call this for model lifecycle events; services call it
 * for explicit events (claim transitions, payments, package
 * generation). The before/after payloads hold only changed scalar
 * fields: sensitive keys are stripped and non-scalar values are
 * reduced to strings, so passwords, tokens, file contents, and
 * storage keys can never land in an audit row.
 */
class AuditService
{
    /**
     * Keys that must never appear in a before/after payload.
     *
     * @var array<int, string>
     */
    protected const DENIED_KEYS = [
        'password',
        'remember_token',
        'token',
        'api_token',
        'secret',
        'storage_key',
        'disk',
        'internal_notes',
    ];

    /**
     * While true, the AuditObserver skips its rows. Services that
     * write their own richer audit entry (claim transitions) wrap
     * their work in withoutAuditing() so the same change is not
     * recorded twice. Direct calls to record() are unaffected.
     */
    protected static bool $suspended = false;

    /**
     * Run a callback with observer auditing suspended.
     */
    public function withoutAuditing(callable $callback): mixed
    {
        $previous = static::$suspended;
        static::$suspended = true;

        try {
            return $callback();
        } finally {
            static::$suspended = $previous;
        }
    }

    /**
     * Whether observer auditing is currently suspended.
     */
    public function isSuspended(): bool
    {
        return static::$suspended;
    }

    /**
     * Record one audit row.
     *
     * The actor defaults to the signed-in user; with no user (console
     * commands, queued jobs) the row is a system row with a null
     * actor id. The correlation id comes from the current request or
     * job run when one is set.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        Organization $organization,
        string $action,
        Model $entity,
        ?User $actor = null,
        ?array $before = null,
        ?array $after = null,
    ): AuditLog {
        $actor ??= Auth::user();

        return AuditLog::create([
            'organization_id' => $organization->getKey(),
            'actor_type' => $actor !== null ? AuditActorType::User : AuditActorType::System,
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'entity_type' => class_basename($entity),
            'entity_id' => (string) $entity->getKey(),
            'before' => $before !== null ? $this->sanitize($before) : null,
            'after' => $after !== null ? $this->sanitize($after) : null,
            'correlation_id' => app(Correlation::class)->get(),
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * Record a row for a tenant-owned entity, deriving the
     * organization from the entity itself.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function recordFor(
        Model $entity,
        string $action,
        ?User $actor = null,
        ?array $before = null,
        ?array $after = null,
    ): ?AuditLog {
        $organizationId = $entity->getAttribute('organization_id');

        if ($organizationId === null) {
            return null;
        }

        $organization = Organization::query()
            ->withoutGlobalScope('organization')
            ->whereKey($organizationId)
            ->first();

        if ($organization === null) {
            return null;
        }

        return $this->record($organization, $action, $entity, $actor, $before, $after);
    }

    /**
     * Strip sensitive keys and reduce values to JSON-safe scalars.
     *
     * @param  array<string, mixed>  $values
     *
     * @return array<string, mixed>
     */
    public function sanitize(array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (in_array($key, self::DENIED_KEYS, true)) {
                continue;
            }

            $clean[$key] = $this->scalarize($value);
        }

        return $clean;
    }

    /**
     * Reduce one value to a JSON-safe scalar or null.
     */
    protected function scalarize(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        return null;
    }
}
