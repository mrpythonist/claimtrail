<?php

namespace App\Tms;

/**
 * Resolves TMS provider drivers (v1.3.2).
 *
 * The registry is the one place that knows which driver
 * names exist and which class implements each. The service
 * and controllers ask the registry, never `new` a provider
 * directly, so adding a vendor later is one entry here plus
 * the provider class. Unknown drivers throw; the API
 * surfaces that as a 422 on integration creation.
 */
class TmsProviderRegistry
{
    /**
     * Driver name to provider class.
     *
     * @var array<string, class-string<TmsProvider>>
     */
    protected static array $drivers = [
        'mock' => MockTmsProvider::class,
        'trimble-tmw' => TrimbleTmwProvider::class,
    ];

    /**
     * Every registered driver name.
     *
     * @return array<int, string>
     */
    public static function drivers(): array
    {
        return array_keys(self::$drivers);
    }

    /**
     * Whether a driver name is registered.
     */
    public static function has(string $driver): bool
    {
        return isset(self::$drivers[$driver]);
    }

    /**
     * Resolve a driver name to its provider instance.
     *
     * @throws TmsProviderException When the driver is unknown.
     */
    public static function resolve(string $driver): TmsProvider
    {
        $class = self::$drivers[$driver] ?? null;

        if ($class === null) {
            throw new TmsProviderException(
                TmsProviderException::CODE_PROVIDER_ERROR,
                'Unknown TMS provider "'.$driver.'". Available providers: '.implode(', ', self::drivers()).'.'
            );
        }

        return app($class);
    }

    /**
     * The provider catalogue for the API and the frontend:
     * one descriptor per registered driver, with its label,
     * whether a live TMS is reachable, its capabilities, and
     * the credential fields it needs (empty for the mock).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function catalogue(): array
    {
        $catalogue = [];

        foreach (self::drivers() as $driver) {
            $provider = self::resolve($driver);

            $catalogue[] = [
                'name' => $provider->name(),
                'label' => $provider->label(),
                'live_available' => $provider->liveAvailable(),
                'capabilities' => $provider->capabilities(),
                'credential_fields' => $provider instanceof TrimbleTmwProvider
                    ? TrimbleTmwProvider::requiredCredentialFields()
                    : [],
                'live_note' => $provider->liveAvailable()
                    ? 'Live TMS access is available through this provider.'
                    : ($provider instanceof TrimbleTmwProvider
                        ? 'Schema-only: live access needs a TMW customer with an on-prem Cloud Hub Connector and a TTC account provisioned through a Trimble representative.'
                        : 'Mock provider: syncs run against deterministic dummy data. No live TMS is contacted.'),
            ];
        }

        return $catalogue;
    }
}
