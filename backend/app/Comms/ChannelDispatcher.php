<?php

namespace App\Comms;

use App\Models\CommunicationChannel;
use App\Models\CommunicationDelivery;

/**
 * Sends event messages through communication channels
 * (v1.3.3).
 *
 * No live delivery exists in v1.3.3: the mock channel
 * records the message locally, and Slack and Teams are
 * schema-only — the vendor payload is built and recorded,
 * but no HTTP call is made. Every attempt is recorded as
 * a CommunicationDelivery with a plain-language detail,
 * so the delivery history is the honest record of what
 * happened. A channel with a malformed webhook URL fails
 * the attempt instead of pretending to send.
 */
class ChannelDispatcher
{
    /**
     * Send one message through one channel and record the
     * delivery. A disabled channel is never sent through:
     * the caller filters those out; sending to one
     * directly is a programming error and throws.
     *
     * @param  array<string, mixed>  $data  Entity links (claim_id and friends).
     */
    public function send(
        CommunicationChannel $channel,
        string $event,
        string $title,
        string $body,
        array $data = [],
    ): CommunicationDelivery {
        if ($channel->status !== CommunicationChannel::STATUS_ACTIVE) {
            throw new \InvalidArgumentException('Channel "'.$channel->name.'" is disabled.');
        }

        $provider = ChannelProviderRegistry::resolve($channel->provider);
        $auth = $provider->authenticate($channel->webhook_url);

        if (! $auth['ok']) {
            return $this->record($channel, $event, $title, $body, null, CommunicationDelivery::STATUS_FAILED, $auth['message']);
        }

        $payload = $provider->buildPayload($event, $title, $body, $data);

        $detail = $channel->provider === 'mock'
            ? 'Logged locally (mock channel): no live delivery.'
            : 'Live delivery unavailable: no '.($channel->provider === 'slack' ? 'Slack workspace' : 'Teams team').' is connected; payload recorded locally.';

        return $this->record($channel, $event, $title, $body, $payload, CommunicationDelivery::STATUS_LOGGED, $detail);
    }

    /**
     * Test one channel: authenticate it and record a test
     * delivery, so the test is visible in the history.
     *
     * @return array{ok: bool, live_available: bool, message: string, delivery_id: string}
     */
    public function test(CommunicationChannel $channel): array
    {
        $provider = ChannelProviderRegistry::resolve($channel->provider);
        $auth = $provider->authenticate($channel->webhook_url);

        $title = 'ClaimTrail channel test';
        $body = 'This is a test message from ClaimTrail for channel "'.$channel->name.'".';

        if (! $auth['ok']) {
            $delivery = $this->record($channel, 'test', $title, $body, null, CommunicationDelivery::STATUS_FAILED, $auth['message']);

            return [...$auth, 'delivery_id' => $delivery->getKey()];
        }

        $payload = $provider->buildPayload('test', $title, $body, []);

        $detail = $channel->provider === 'mock'
            ? 'Logged locally (mock channel): no live delivery.'
            : 'Live delivery unavailable: no '.($channel->provider === 'slack' ? 'Slack workspace' : 'Teams team').' is connected; payload recorded locally.';

        $delivery = $this->record($channel, 'test', $title, $body, $payload, CommunicationDelivery::STATUS_LOGGED, $detail);

        return [...$auth, 'delivery_id' => $delivery->getKey()];
    }

    /**
     * Record one delivery attempt.
     *
     * @param  array<string, mixed>|null  $payload
     */
    protected function record(
        CommunicationChannel $channel,
        string $event,
        string $title,
        string $body,
        ?array $payload,
        string $status,
        string $detail,
    ): CommunicationDelivery {
        return CommunicationDelivery::create([
            'organization_id' => $channel->organization_id,
            'communication_channel_id' => $channel->getKey(),
            'event' => $event,
            'title' => $title,
            'body' => $body,
            'status' => $status,
            'detail' => $detail,
            'payload' => $payload,
            'attempted_at' => now(),
        ]);
    }
}
