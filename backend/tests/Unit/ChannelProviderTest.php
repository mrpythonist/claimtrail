<?php

namespace Tests\Unit;

use App\Comms\ChannelProviderRegistry;
use App\Comms\MockChannelProvider;
use App\Comms\SlackChannelProvider;
use App\Comms\TeamsChannelProvider;
use PHPUnit\Framework\TestCase;

/**
 * Channel providers (v1.3.3).
 *
 * The mock records locally; Slack and Teams model the
 * vendors' published incoming-webhook shapes and never
 * perform a live call. No provider in v1.3.3 can reach a
 * live channel.
 */
class ChannelProviderTest extends TestCase
{
    public function test_registry_lists_three_drivers_in_order(): void
    {
        $this->assertSame(['mock', 'slack', 'teams'], ChannelProviderRegistry::drivers());
        $this->assertTrue(ChannelProviderRegistry::has('slack'));
        $this->assertFalse(ChannelProviderRegistry::has('discord'));
    }

    public function test_no_provider_is_live(): void
    {
        foreach (ChannelProviderRegistry::drivers() as $driver) {
            $this->assertFalse(ChannelProviderRegistry::resolve($driver)->liveAvailable());
        }
    }

    public function test_mock_needs_no_webhook_url(): void
    {
        $provider = new MockChannelProvider;

        $this->assertFalse($provider->webhookUrlRequired());
        $this->assertTrue($provider->validateWebhookUrl(null));
        $this->assertTrue($provider->authenticate(null)['ok']);

        $payload = $provider->buildPayload('claim_created', 'Claim created', 'A claim was created.');
        $this->assertTrue($payload['mock']);
        $this->assertSame('claim_created', $payload['event']);
    }

    public function test_slack_validates_the_webhook_url_shape(): void
    {
        $provider = new SlackChannelProvider;

        $this->assertTrue($provider->webhookUrlRequired());
        $this->assertFalse($provider->validateWebhookUrl(null));
        $this->assertFalse($provider->validateWebhookUrl('https://example.com/hook'));
        $this->assertFalse($provider->validateWebhookUrl('http://hooks.slack.com/services/T000/B000/XXXX'));
        $this->assertTrue($provider->validateWebhookUrl('https://hooks.slack.com/services/T000/B000/XXXX'));

        $good = $provider->authenticate('https://hooks.slack.com/services/T000/B000/XXXX');
        $this->assertTrue($good['ok']);
        $this->assertFalse($good['live_available']);

        $bad = $provider->authenticate('https://example.com/hook');
        $this->assertFalse($bad['ok']);

        $payload = $provider->buildPayload('test', 'Test', 'Body.');
        $this->assertSame("Test\nBody.", $payload['text']);
        $this->assertSame('section', $payload['blocks'][0]['type']);
    }

    public function test_teams_validates_the_webhook_url_shape(): void
    {
        $provider = new TeamsChannelProvider;

        $this->assertTrue($provider->webhookUrlRequired());
        $this->assertFalse($provider->validateWebhookUrl(null));
        $this->assertFalse($provider->validateWebhookUrl('https://hooks.slack.com/services/T000/B000/XXXX'));
        $this->assertTrue($provider->validateWebhookUrl('https://outlook.office.com/webhook/abc/IncomingWebhook/def/123'));
        $this->assertTrue($provider->validateWebhookUrl('https://outlook.office365.com/webhook/abc/IncomingWebhook/def/123'));

        $good = $provider->authenticate('https://outlook.office.com/webhook/abc/IncomingWebhook/def/123');
        $this->assertTrue($good['ok']);
        $this->assertFalse($good['live_available']);

        $payload = $provider->buildPayload('test', 'Test', 'Body.');
        $this->assertSame('MessageCard', $payload['@type']);
        $this->assertSame('Test', $payload['summary']);
    }

    public function test_catalogue_marks_every_provider_not_live(): void
    {
        $catalogue = ChannelProviderRegistry::catalogue();

        $this->assertCount(3, $catalogue);

        foreach ($catalogue as $provider) {
            $this->assertFalse($provider['live_available']);
            $this->assertArrayHasKey('webhook_url_hint', $provider);
        }
    }
}
