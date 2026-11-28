<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * API key issuance, rotation, and revocation (v2.0.2).
 *
 * Keys are Bearer tokens for the public API. A key is
 * generated as `ct_live_` plus 40 random characters; only
 * its SHA-256 hash is stored, so a database read can never
 * reveal a usable key. The full key is returned exactly
 * once, at creation or rotation, and the service never
 * returns it again. key_prefix (the first 12 characters)
 * is stored so a key can be recognised in lists.
 *
 * Scopes (the full vocabulary lives here, and only here):
 * claims:read, claims:write, shipments:read,
 * payments:read, documents:read, broker:status. Broker keys
 * may only carry claims:read and broker:status, and must
 * name the broker they belong to.
 *
 * Rotation issues a replacement key and moves the old key
 * to rotating with a grace window (config
 * public_api.rotation_grace_hours, 24 hours by default):
 * the old key keeps working until the window ends, then it
 * is refused. Revocation is immediate and permanent.
 */
class ApiKeyService
{
    public const SCOPE_CLAIMS_READ = 'claims:read';

    public const SCOPE_CLAIMS_WRITE = 'claims:write';

    public const SCOPE_SHIPMENTS_READ = 'shipments:read';

    public const SCOPE_PAYMENTS_READ = 'payments:read';

    public const SCOPE_DOCUMENTS_READ = 'documents:read';

    public const SCOPE_BROKER_STATUS = 'broker:status';

    /**
     * Every scope the public API understands, in the order
     * the developer docs list them.
     *
     * @return array<int, string>
     */
    public static function scopes(): array
    {
        return [
            self::SCOPE_CLAIMS_READ,
            self::SCOPE_CLAIMS_WRITE,
            self::SCOPE_SHIPMENTS_READ,
            self::SCOPE_PAYMENTS_READ,
            self::SCOPE_DOCUMENTS_READ,
            self::SCOPE_BROKER_STATUS,
        ];
    }

    /**
     * Scopes a broker key may carry. Broker access is
     * read/status only: a broker key can never write.
     *
     * @return array<int, string>
     */
    public static function brokerScopes(): array
    {
        return [
            self::SCOPE_CLAIMS_READ,
            self::SCOPE_BROKER_STATUS,
        ];
    }

    /**
     * The SHA-256 hash stored for a presented key.
     */
    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * Issue a key. Returns the created row and the full key,
     * which the caller must show exactly once and never store.
     *
     * @param  array<int, string>  $scopes
     * @return array{key: ApiKey, plain_text: string}
     */
    public function create(
        Organization $organization,
        User $creator,
        string $name,
        array $scopes,
        string $kind = ApiKey::KIND_STANDARD,
        ?string $brokerName = null,
        ?string $expiresAt = null,
    ): array {
        $scopes = $this->validateScopes($scopes, $kind, $brokerName);

        $plainText = config('public_api.key_prefix').Str::random(40);

        $key = ApiKey::create([
            'organization_id' => $organization->getKey(),
            'name' => $name,
            'kind' => $kind,
            'key_prefix' => substr($plainText, 0, 12),
            'key_hash' => self::hash($plainText),
            'scopes' => $scopes,
            'status' => ApiKey::STATUS_ACTIVE,
            'broker_name' => $brokerName,
            'expires_at' => $expiresAt,
            'created_by' => $creator->getKey(),
        ]);

        return ['key' => $key, 'plain_text' => $plainText];
    }

    /**
     * Rotate a key: issue its replacement and move the old
     * key into its grace window. The replacement carries the
     * same kind, scopes, and broker name; the old key keeps
     * working until the grace window ends.
     *
     * @return array{key: ApiKey, plain_text: string}
     */
    public function rotate(ApiKey $key, User $rotator): array
    {
        if ($key->status === ApiKey::STATUS_REVOKED) {
            throw ValidationException::withMessages([
                'key' => 'A revoked key cannot be rotated.',
            ]);
        }

        $graceHours = (int) config('public_api.rotation_grace_hours', 24);

        $replacement = $this->create(
            $key->organization,
            $rotator,
            $key->name.' (rotated)',
            $key->scopes ?? [],
            $key->kind,
            $key->broker_name,
            $key->expires_at?->toIso8601String(),
        );

        $replacement['key']->update(['rotated_from_id' => $key->getKey()]);

        // A key already inside a grace window is revoked
        // outright when it is rotated again: two live grace
        // windows for one lineage would let an old key outlive
        // its replacement.
        if ($key->status === ApiKey::STATUS_ROTATING) {
            $key->update([
                'status' => ApiKey::STATUS_REVOKED,
                'revoked_at' => now(),
                'revoked_by' => $rotator->getKey(),
            ]);
        } else {
            $key->update([
                'status' => ApiKey::STATUS_ROTATING,
                'grace_ends_at' => now()->addHours($graceHours),
            ]);
        }

        return $replacement;
    }

    /**
     * Revoke a key. Immediate and permanent: the key is
     * refused with a 401 from this moment on.
     */
    public function revoke(ApiKey $key, User $revoker): ApiKey
    {
        if ($key->status !== ApiKey::STATUS_REVOKED) {
            $key->update([
                'status' => ApiKey::STATUS_REVOKED,
                'revoked_at' => now(),
                'revoked_by' => $revoker->getKey(),
            ]);
        }

        return $key->fresh();
    }

    /**
     * Validate a scope list for a key kind. Unknown scopes
     * are rejected, broker keys are restricted to the
     * read/status scopes, and a broker key must name its
     * broker. Standard keys may not carry broker:status:
     * that scope exists so a broker's key is recognisable
     * as a broker key.
     *
     * @param  array<int, string>  $scopes
     * @return array<int, string>
     */
    protected function validateScopes(array $scopes, string $kind, ?string $brokerName): array
    {
        $scopes = array_values(array_unique($scopes));

        if ($scopes === []) {
            throw ValidationException::withMessages([
                'scopes' => 'At least one scope is required.',
            ]);
        }

        foreach ($scopes as $scope) {
            if (! in_array($scope, self::scopes(), true)) {
                throw ValidationException::withMessages([
                    'scopes' => "Unknown scope: {$scope}.",
                ]);
            }
        }

        if ($kind === ApiKey::KIND_BROKER) {
            foreach ($scopes as $scope) {
                if (! in_array($scope, self::brokerScopes(), true)) {
                    throw ValidationException::withMessages([
                        'scopes' => "Broker keys may only carry claims:read and broker:status.",
                    ]);
                }
            }

            if ($brokerName === null || trim($brokerName) === '') {
                throw ValidationException::withMessages([
                    'broker_name' => 'A broker key must name the broker it belongs to.',
                ]);
            }
        }

        if ($kind === ApiKey::KIND_STANDARD && in_array(self::SCOPE_BROKER_STATUS, $scopes, true)) {
            throw ValidationException::withMessages([
                'scopes' => 'broker:status is only available on broker keys.',
            ]);
        }

        return $scopes;
    }

    /**
     * The public presentation of a key. The hash is never
     * included, and the full key is never included: it was
     * shown once, at creation or rotation.
     *
     * @return array<string, mixed>
     */
    public function present(ApiKey $key): array
    {
        return [
            'id' => $key->getKey(),
            'organization_id' => $key->organization_id,
            'name' => $key->name,
            'kind' => $key->kind,
            'key_prefix' => $key->key_prefix,
            'scopes' => $key->scopes ?? [],
            'status' => $key->status,
            'broker_name' => $key->broker_name,
            'rotated_from_id' => $key->rotated_from_id,
            'grace_ends_at' => $key->grace_ends_at?->toIso8601String(),
            'last_used_at' => $key->last_used_at?->toIso8601String(),
            'expires_at' => $key->expires_at?->toIso8601String(),
            'revoked_at' => $key->revoked_at?->toIso8601String(),
            'created_at' => $key->created_at?->toIso8601String(),
            'updated_at' => $key->updated_at?->toIso8601String(),
        ];
    }
}
