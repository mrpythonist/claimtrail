<?php

namespace App\Comms;

/**
 * The mock channel provider (v1.3.3).
 *
 * Records messages locally and never delivers anywhere.
 * The test channel: connection tests, delivery history,
 * and event fan-out all verify end to end against it.
 */
class MockChannelProvider implements ChannelProvider
{
    public function name(): string
    {
        return 'mock';
    }

    public function label(): string
    {
        return 'Mock channel';
    }

    public function liveAvailable(): bool
    {
        return false;
    }

    public function webhookUrlRequired(): bool
    {
        return false;
    }

    public function webhookUrlHint(): string
    {
        return 'No webhook URL is needed for the mock channel.';
    }

    public function validateWebhookUrl(?string $url): bool
    {
        return true;
    }

    public function authenticate(?string $webhookUrl): array
    {
        return [
            'ok' => true,
            'live_available' => false,
            'message' => 'Mock channel is ready. Messages are recorded locally and never delivered anywhere.',
        ];
    }

    public function buildPayload(string $event, string $title, string $body, array $data = []): array
    {
        return [
            'mock' => true,
            'event' => $event,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ];
    }
}
