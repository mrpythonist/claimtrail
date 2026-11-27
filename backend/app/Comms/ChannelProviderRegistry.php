<?php

namespace App\Comms;

use InvalidArgumentException;

/**
 * Resolves channel provider driver names (v1.3.3).
 *
 * Mirrors the TMS provider registry: the driver name is
 * stored on the channel, and everything else resolves
 * through here, so adding a live provider later means
 * one new class and one registry line.
 */
class ChannelProviderRegistry
{
    /**
     * Every registered driver name, in catalogue order.
     *
     * @return array<int, string>
     */
    public static function drivers(): array
    {
        return ['mock', 'slack', 'teams'];
    }

    public static function has(string $driver): bool
    {
        return in_array($driver, self::drivers(), true);
    }

    public static function resolve(string $driver): ChannelProvider
    {
        return match ($driver) {
            'mock' => new MockChannelProvider,
            'slack' => new SlackChannelProvider,
            'teams' => new TeamsChannelProvider,
            default => throw new InvalidArgumentException('Unknown channel provider "'.$driver.'".'),
        };
    }

    /**
     * The provider catalogue, for the API and the frontend.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function catalogue(): array
    {
        return array_map(function (string $driver) {
            $provider = self::resolve($driver);

            return [
                'name' => $provider->name(),
                'label' => $provider->label(),
                'live_available' => $provider->liveAvailable(),
                'webhook_url_required' => $provider->webhookUrlRequired(),
                'webhook_url_hint' => $provider->webhookUrlHint(),
            ];
        }, self::drivers());
    }
}
