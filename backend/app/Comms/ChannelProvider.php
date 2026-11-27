<?php

namespace App\Comms;

/**
 * The channel provider contract (v1.3.3).
 *
 * Every channel message the app sends goes through this
 * interface, so the provider is a swap, not a rewrite.
 * The mock provider (MockChannelProvider) records the
 * message locally; Slack and Teams
 * (SlackChannelProvider, TeamsChannelProvider) model the
 * vendors' published incoming-webhook payload shapes and
 * never perform a live call. A real live provider would
 * implement the same methods against the vendor's webhook
 * API.
 *
 * A provider never writes to the app database and never
 * creates app state: it answers provider-side questions
 * and returns the payload a live call would send. The
 * ChannelDispatcher records the attempt as a
 * CommunicationDelivery.
 *
 * Authentication result shape:
 *
 *   [
 *     'ok' => bool,               // the webhook URL is well-formed
 *     'live_available' => bool,   // a live channel can actually be reached
 *     'message' => '...',         // plain-language explanation
 *   ]
 */
interface ChannelProvider
{
    /**
     * The driver name, e.g. "mock", "slack", or "teams".
     * Stored on the channel so a later swap can tell which
     * provider produced a delivery.
     */
    public function name(): string;

    /**
     * The human-readable provider label, e.g. "Mock channel",
     * "Slack", or "Microsoft Teams".
     */
    public function label(): string;

    /**
     * Whether a live channel can be reached through this
     * provider from this app. False for every provider in
     * v1.3.3: the mock is local-only by design, and Slack
     * and Teams are schema-only until a live workspace or
     * team is connected.
     */
    public function liveAvailable(): bool;

    /**
     * Whether the provider needs a webhook URL at all. The
     * mock needs none; Slack and Teams do.
     */
    public function webhookUrlRequired(): bool;

    /**
     * A plain-language hint for what a valid webhook URL
     * looks like, for the settings form. No real URL is
     * ever shown here.
     */
    public function webhookUrlHint(): string;

    /**
     * Whether a webhook URL has the shape this provider
     * expects. Never performs a live call.
     */
    public function validateWebhookUrl(?string $url): bool;

    /**
     * Validate the stored webhook URL for this provider.
     * Never performs a live call: the mock accepts any
     * (it needs none), and Slack and Teams check the URL
     * shape against the vendor's published webhook format.
     *
     * @return array{ok: bool, live_available: bool, message: string}
     */
    public function authenticate(?string $webhookUrl): array;

    /**
     * Build the payload a live call would send for an
     * event message. This is the schema model: it documents
     * the vendor payload surface and is never executed by
     * a schema-only provider. The mock returns a mock://
     * descriptor.
     *
     * @param  array<string, mixed>  $data  Entity links (claim_id and friends).
     * @return array<string, mixed>
     */
    public function buildPayload(string $event, string $title, string $body, array $data = []): array;
}
