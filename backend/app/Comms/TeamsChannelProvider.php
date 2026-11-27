<?php

namespace App\Comms;

/**
 * The Microsoft Teams channel provider, schema-only
 * (v1.3.3).
 *
 * Models the published Teams incoming-webhook surface:
 * a webhook URL at outlook.office.com (or
 * outlook.office365.com) /webhook/… and a MessageCard
 * payload. It never performs a live call: there is no
 * Teams team connected, and no message ever leaves this
 * app. Live delivery needs a real team with an Office 365
 * connector webhook (or a Workflow webhook) and the
 * webhook URL stored on the channel.
 */
class TeamsChannelProvider implements ChannelProvider
{
    public function name(): string
    {
        return 'teams';
    }

    public function label(): string
    {
        return 'Microsoft Teams';
    }

    public function liveAvailable(): bool
    {
        return false;
    }

    public function webhookUrlRequired(): bool
    {
        return true;
    }

    public function webhookUrlHint(): string
    {
        return 'A Teams incoming-webhook URL, starting with https://outlook.office.com/webhook/ (or outlook.office365.com).';
    }

    public function validateWebhookUrl(?string $url): bool
    {
        if ($url === null || trim($url) === '') {
            return false;
        }

        $parts = parse_url(trim($url));

        if ($parts === false || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        if (! in_array($parts['host'] ?? '', ['outlook.office.com', 'outlook.office365.com'], true)) {
            return false;
        }

        return str_starts_with($parts['path'] ?? '', '/webhook/');
    }

    public function authenticate(?string $webhookUrl): array
    {
        if (! $this->validateWebhookUrl($webhookUrl)) {
            return [
                'ok' => false,
                'live_available' => false,
                'message' => 'The Teams webhook URL is missing or not an outlook.office.com/webhook/ URL.',
            ];
        }

        return [
            'ok' => true,
            'live_available' => false,
            'message' => 'The Teams webhook URL is well-formed, but no live delivery is possible: no Teams team is connected.',
        ];
    }

    public function buildPayload(string $event, string $title, string $body, array $data = []): array
    {
        return [
            '@type' => 'MessageCard',
            '@context' => 'http://schema.org/extensions',
            'themeColor' => '0078D4',
            'summary' => $title,
            'sections' => [
                [
                    'activityTitle' => $title,
                    'text' => $body,
                ],
            ],
        ];
    }
}
