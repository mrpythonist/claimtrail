<?php

namespace App\Comms;

/**
 * The Slack channel provider, schema-only (v1.3.3).
 *
 * Models the published Slack incoming-webhook surface:
 * a webhook URL at hooks.slack.com/services/… and a
 * message with text plus one Block Kit section. It never
 * performs a live call: there is no Slack workspace
 * connected, and no message ever leaves this app. Live
 * delivery needs a real workspace with an incoming
 * webhook (or a Slack app) and the webhook URL stored on
 * the channel.
 */
class SlackChannelProvider implements ChannelProvider
{
    public function name(): string
    {
        return 'slack';
    }

    public function label(): string
    {
        return 'Slack';
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
        return 'A Slack incoming-webhook URL, starting with https://hooks.slack.com/services/.';
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

        if (($parts['host'] ?? '') !== 'hooks.slack.com') {
            return false;
        }

        return str_starts_with($parts['path'] ?? '', '/services/');
    }

    public function authenticate(?string $webhookUrl): array
    {
        if (! $this->validateWebhookUrl($webhookUrl)) {
            return [
                'ok' => false,
                'live_available' => false,
                'message' => 'The Slack webhook URL is missing or not a hooks.slack.com/services/ URL.',
            ];
        }

        return [
            'ok' => true,
            'live_available' => false,
            'message' => 'The Slack webhook URL is well-formed, but no live delivery is possible: no Slack workspace is connected.',
        ];
    }

    public function buildPayload(string $event, string $title, string $body, array $data = []): array
    {
        $text = $title;

        if (trim($body) !== '') {
            $text .= "\n".$body;
        }

        return [
            'text' => $text,
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => '*'.$title.'*'."\n".$body,
                    ],
                ],
            ],
        ];
    }
}
