<?php

namespace App\Services;

use App\Comms\ChannelDispatcher;
use App\Comms\ChannelProviderRegistry;
use App\EvidenceDocumentCategory;
use App\Mail\DriverEvidenceLinkMail;
use App\Models\Claim;
use App\Models\CommunicationChannel;
use App\Models\CommunicationDelivery;
use App\Models\DriverEvidenceLink;
use App\Models\DriverEvidenceSubmission;
use App\Models\EvidenceFile;
use App\Models\Organization;
use App\Models\Shipment;
use App\Models\User;
use App\NotificationType;
use App\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Communication integrations (v1.3.3).
 *
 * Channel connections (Slack, Teams, mock) behind a
 * provider interface, with a dispatcher that records
 * every attempt in the delivery history. No live
 * delivery exists: the mock records locally and Slack
 * and Teams are schema-only, so every send is logged,
 * never sent. Driver evidence links are signed, scoped,
 * expiring, single-use-by-default tokens that let a
 * driver submit one evidence file without an account.
 * Email workflows are the driver-link email and the
 * notification emails the NotificationService already
 * queues per member preference; a failed link email is
 * caught, logged, and notified instead of failing the
 * link creation.
 */
class CommunicationService
{
    public function __construct(
        protected AuditService $audit,
        protected NotificationService $notifications,
        protected ChannelDispatcher $dispatcher,
    ) {}

    /**
     * Disk for driver-submitted evidence (the evidence disk).
     */
    public static function disk(): string
    {
        return config('evidence.disk');
    }

    /**
     * The provider catalogue, for the API and the frontend.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function providers(): array
    {
        return ChannelProviderRegistry::catalogue();
    }

    /**
     * Validate and normalize a channel settings body.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, bool>
     */
    public static function validatedSettings(array $settings): array
    {
        $clean = [];

        foreach (CommunicationChannel::EVENTS as $event) {
            $clean[$event] = (bool) ($settings[$event] ?? true);
        }

        return $clean;
    }

    /**
     * Validate a webhook URL for a provider. The mock needs
     * none; Slack and Teams need a well-formed vendor URL.
     *
     * @throws ValidationException
     */
    public static function validatedWebhookUrl(string $provider, ?string $url): ?string
    {
        $providerInstance = ChannelProviderRegistry::resolve($provider);

        if ($url === null || trim($url) === '') {
            if ($providerInstance->webhookUrlRequired()) {
                throw ValidationException::withMessages(['webhook_url' => [
                    $providerInstance->label().' needs a webhook URL. '.$providerInstance->webhookUrlHint(),
                ]]);
            }

            return null;
        }

        if (! $providerInstance->validateWebhookUrl($url)) {
            throw ValidationException::withMessages(['webhook_url' => [
                'That is not a valid '.$providerInstance->label().' webhook URL. '.$providerInstance->webhookUrlHint(),
            ]]);
        }

        return trim($url);
    }

    /**
     * The API payload for one channel. The webhook URL is
     * never returned: the payload says only whether one is
     * stored.
     *
     * @return array<string, mixed>
     */
    public function channelPayload(CommunicationChannel $channel): array
    {
        $provider = ChannelProviderRegistry::resolve($channel->provider);

        return [
            'id' => (string) $channel->getKey(),
            'provider' => $channel->provider,
            'provider_label' => $provider->label(),
            'live_available' => $provider->liveAvailable(),
            'name' => $channel->name,
            'status' => $channel->status,
            'settings' => $channel->effectiveSettings(),
            'has_webhook_url' => $channel->webhook_url !== null,
            'last_tested_at' => $channel->last_tested_at?->toIso8601String(),
            'last_error' => $channel->last_error,
            'created_at' => $channel->created_at?->toIso8601String(),
            'updated_at' => $channel->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The API payload for one delivery.
     *
     * @return array<string, mixed>
     */
    public function deliveryPayload(CommunicationDelivery $delivery): array
    {
        return [
            'id' => (string) $delivery->getKey(),
            'communication_channel_id' => (string) $delivery->communication_channel_id,
            'event' => $delivery->event,
            'title' => $delivery->title,
            'body' => $delivery->body,
            'status' => $delivery->status,
            'detail' => $delivery->detail,
            'payload' => $delivery->payload,
            'attempted_at' => $delivery->attempted_at?->toIso8601String(),
            'created_at' => $delivery->created_at?->toIso8601String(),
            'updated_at' => $delivery->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The API payload for one driver link. The raw token is
     * only included when it is freshly created or rotated:
     * it is shown once and never stored.
     *
     * @return array<string, mixed>
     */
    public function linkPayload(DriverEvidenceLink $link, ?string $rawToken = null): array
    {
        $shipment = $link->shipment;
        $claim = $link->claim;

        $payload = [
            'id' => (string) $link->getKey(),
            'shipment_id' => $link->shipment_id !== null ? (string) $link->shipment_id : null,
            'claim_id' => $link->claim_id !== null ? (string) $link->claim_id : null,
            'shipment_reference' => $shipment?->internal_reference ?? $shipment?->external_load_reference,
            'claim_reference' => $claim?->claim_reference,
            'driver_name' => $link->driver_name,
            'driver_email' => $link->driver_email,
            'state' => $link->state(),
            'expires_at' => $link->expires_at?->toIso8601String(),
            'max_uses' => $link->max_uses,
            'used_count' => (int) $link->used_count,
            'uses_left' => max(0, $link->max_uses - (int) $link->used_count),
            'revoked_at' => $link->revoked_at?->toIso8601String(),
            'created_at' => $link->created_at?->toIso8601String(),
            'updated_at' => $link->updated_at?->toIso8601String(),
        ];

        if ($rawToken !== null) {
            $payload['submit_url'] = rtrim((string) config('app.frontend_url'), '/').'/driver-evidence/'.$rawToken;
            $payload['token'] = $rawToken;
        }

        return $payload;
    }

    /**
     * The public payload for one driver link: what a driver
     * sees on the submission page. No organization internals
     * beyond the names and references the link covers.
     *
     * @return array<string, mixed>
     */
    public function publicLinkPayload(DriverEvidenceLink $link): array
    {
        $shipment = $link->shipment;
        $claim = $link->claim;

        return [
            'organization_name' => $link->organization->name,
            'shipment_reference' => $shipment?->internal_reference ?? $shipment?->external_load_reference,
            'claim_reference' => $claim?->claim_reference,
            'driver_name' => $link->driver_name,
            'state' => $link->state(),
            'expires_at' => $link->expires_at?->toIso8601String(),
            'uses_left' => max(0, $link->max_uses - (int) $link->used_count),
        ];
    }

    /**
     * The API payload for one driver submission.
     *
     * @return array<string, mixed>
     */
    public function submissionPayload(DriverEvidenceSubmission $submission): array
    {
        return [
            'id' => (string) $submission->getKey(),
            'driver_evidence_link_id' => (string) $submission->driver_evidence_link_id,
            'shipment_id' => $submission->shipment_id !== null ? (string) $submission->shipment_id : null,
            'claim_id' => $submission->claim_id !== null ? (string) $submission->claim_id : null,
            'evidence_file_id' => $submission->evidence_file_id !== null ? (string) $submission->evidence_file_id : null,
            'driver_name' => $submission->driver_name,
            'notes' => $submission->notes,
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'created_at' => $submission->created_at?->toIso8601String(),
        ];
    }

    /**
     * Create a channel. Names are unique per organization.
     */
    public function createChannel(
        Organization $organization,
        User $actor,
        string $provider,
        string $name,
        ?string $webhookUrl,
        array $settings,
    ): CommunicationChannel {
        if (! ChannelProviderRegistry::has($provider)) {
            throw ValidationException::withMessages(['provider' => [
                'Unknown channel provider "'.$provider.'".',
            ]]);
        }

        $webhookUrl = self::validatedWebhookUrl($provider, $webhookUrl);
        $settings = self::validatedSettings($settings);

        $channel = DB::transaction(fn () => CommunicationChannel::create([
            'organization_id' => $organization->getKey(),
            'provider' => $provider,
            'name' => $name,
            'status' => CommunicationChannel::STATUS_ACTIVE,
            'webhook_url' => $webhookUrl,
            'settings' => $settings,
            'created_by' => $actor->getKey(),
        ]));

        $this->audit->record($organization, 'comms.channel_created', $channel, $actor);
        $this->notifyChannel($organization, $channel, $actor, NotificationType::CommunicationChannelConnected, 'Communication channel connected', $channel->name.' ('.ChannelProviderRegistry::resolve($provider)->label().') is connected. No live delivery yet: messages are recorded in the delivery history.');

        return $channel;
    }

    /**
     * Update a channel. The webhook URL is only replaced
     * when supplied.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateChannel(CommunicationChannel $channel, User $actor, array $data): CommunicationChannel
    {
        $organization = $channel->organization;

        if (array_key_exists('webhook_url', $data)) {
            $channel->webhook_url = self::validatedWebhookUrl(
                $channel->provider,
                is_string($data['webhook_url']) ? $data['webhook_url'] : null
            );
        }

        if (array_key_exists('settings', $data) && is_array($data['settings'])) {
            $channel->settings = self::validatedSettings(
                array_merge($channel->effectiveSettings(), $data['settings'])
            );
        }

        if (array_key_exists('name', $data)) {
            $channel->name = $data['name'];
        }

        if (array_key_exists('status', $data)) {
            $channel->status = $data['status'];
        }

        $channel->save();

        $this->audit->record($organization, 'comms.channel_updated', $channel, $actor);

        return $channel->refresh();
    }

    /**
     * Delete a channel. Its deliveries go with it
     * (cascade).
     */
    public function deleteChannel(CommunicationChannel $channel, User $actor): void
    {
        $organization = $channel->organization;

        $channel->delete();

        $this->audit->record($organization, 'comms.channel_deleted', $channel, $actor);
    }

    /**
     * Test a channel: authenticate it and record a test
     * delivery, so the test is visible in the history.
     *
     * @return array{ok: bool, live_available: bool, message: string}
     */
    public function testChannel(CommunicationChannel $channel, ?User $actor): array
    {
        $organization = $channel->organization;

        // A disabled channel cannot be tested: like a
        // disabled TMS integration, it fails fast instead
        // of recording a test for a channel that will not
        // send. The test is not recorded and last_tested_at
        // is left alone.
        if ($channel->status !== CommunicationChannel::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['channel' => [
                'Channel "'.$channel->name.'" is disabled.',
            ]]);
        }

        $result = $this->dispatcher->test($channel);

        $channel->update([
            'last_tested_at' => now(),
            'last_error' => $result['ok'] ? null : $result['message'],
        ]);

        $this->audit->record($organization, 'comms.channel_tested', $channel, $actor);
        $this->notifyChannel($organization, $channel, $actor, NotificationType::CommunicationChannelTested, 'Communication channel tested', $result['message'], 'comms-channel:'.$channel->getKey().':tested:'.Str::uuid());

        return $result;
    }

    /**
     * Send an event to every active channel in the
     * organization that wants it. Returns the recorded
     * deliveries. A failed delivery updates the channel's
     * last_error; it never throws, so one bad channel
     * cannot block the others.
     *
     * @param  array<string, mixed>  $data  Entity links (claim_id and friends).
     * @return array<int, CommunicationDelivery>
     */
    public function sendEvent(
        Organization $organization,
        string $event,
        string $title,
        string $body,
        array $data = [],
        ?User $actor = null,
    ): array {
        $deliveries = [];

        $channels = CommunicationChannel::forOrganization($organization)
            ->where('status', CommunicationChannel::STATUS_ACTIVE)
            ->orderBy('created_at')
            ->get();

        foreach ($channels as $channel) {
            if (! $channel->wantsEvent($event)) {
                continue;
            }

            $delivery = $this->dispatcher->send($channel, $event, $title, $body, $data);
            $deliveries[] = $delivery;

            if ($delivery->status === CommunicationDelivery::STATUS_FAILED) {
                $channel->update(['last_error' => $delivery->detail]);
            }
        }

        return $deliveries;
    }

    /**
     * Create a driver evidence link. The scope is one
     * shipment, or one claim, or one shipment plus its
     * claim; at least one is required, and both must
     * belong to this organization. The raw token is
     * returned once, with the link; only its hash is
     * stored.
     *
     * @return array{link: DriverEvidenceLink, token: string}
     */
    public function createDriverLink(
        Organization $organization,
        User $actor,
        ?string $shipmentId,
        ?string $claimId,
        ?string $driverName,
        ?string $driverEmail,
        ?int $ttlHours,
        ?int $maxUses,
    ): array {
        if ($shipmentId === null && $claimId === null) {
            throw ValidationException::withMessages(['shipment_id' => [
                'A driver link needs a shipment or a claim to cover.',
            ]]);
        }

        $shipment = $this->resolveLinkShipment($organization, $shipmentId);
        $claim = $this->resolveLinkClaim($organization, $claimId);

        if ($shipment !== null && $claim !== null && $claim->shipment_id !== null && $claim->shipment_id !== $shipment->getKey()) {
            throw ValidationException::withMessages(['claim_id' => [
                'That claim does not belong to that shipment.',
            ]]);
        }

        if ($shipment !== null && $shipment->isArchived()) {
            throw ValidationException::withMessages(['shipment_id' => [
                'Evidence cannot be added to an archived shipment.',
            ]]);
        }

        $ttlHours = $ttlHours ?? (int) config('comms.driver_link_ttl_hours', 72);
        $ttlHours = max(1, min(720, $ttlHours));
        $maxUses = $maxUses ?? (int) config('comms.driver_link_max_uses', 1);
        $maxUses = max(1, min(10, $maxUses));

        $rawToken = Str::random(48);

        $link = DB::transaction(fn () => DriverEvidenceLink::create([
            'organization_id' => $organization->getKey(),
            'shipment_id' => $shipment?->getKey(),
            'claim_id' => $claim?->getKey(),
            'token_hash' => hash('sha256', $rawToken),
            'driver_name' => $driverName,
            'driver_email' => $driverEmail !== null ? Str::lower($driverEmail) : null,
            'expires_at' => now()->addHours($ttlHours),
            'max_uses' => $maxUses,
            'used_count' => 0,
            'created_by' => $actor->getKey(),
        ]));

        $this->audit->record($organization, 'comms.driver_link_created', $link, $actor);

        $this->notifyLinkMembers(
            $organization,
            $link,
            $actor,
            NotificationType::DriverLinkCreated,
            'Driver evidence link created',
            'A submission link was created for '.($shipment?->internal_reference ?? $claim?->claim_reference ?? 'a shipment').'. It expires '.$link->expires_at->toDayDateTimeString().' and can be used '.$link->max_uses.($link->max_uses === 1 ? ' time.' : ' times.')
        );

        if ($link->driver_email !== null) {
            $this->sendDriverLinkEmail($organization, $link, $actor, $rawToken);
        }

        return ['link' => $link, 'token' => $rawToken];
    }

    /**
     * Resend a driver link email. The token rotates: a new
     * raw token is issued and the old URL stops working,
     * because the old token was never stored. Only usable
     * links with a driver email can be resent.
     *
     * @return array{link: DriverEvidenceLink, token: string}
     */
    public function resendDriverLinkEmail(DriverEvidenceLink $link, User $actor): array
    {
        $organization = $link->organization;

        if (! $link->isUsable()) {
            throw ValidationException::withMessages(['link' => [
                'That link is '.$link->state().' and cannot be resent.',
            ]]);
        }

        if ($link->driver_email === null) {
            throw ValidationException::withMessages(['driver_email' => [
                'That link has no driver email address to send to.',
            ]]);
        }

        $rawToken = Str::random(48);
        $link->update(['token_hash' => hash('sha256', $rawToken)]);

        $this->audit->record($organization, 'comms.driver_link_resent', $link, $actor);
        $this->sendDriverLinkEmail($organization, $link, $actor, $rawToken);

        return ['link' => $link->refresh(), 'token' => $rawToken];
    }

    /**
     * Revoke a driver link before its expiry. Revocation
     * is immediate and permanent.
     */
    public function revokeDriverLink(DriverEvidenceLink $link, User $actor): DriverEvidenceLink
    {
        $organization = $link->organization;

        if ($link->isRevoked()) {
            return $link;
        }

        $link->update(['revoked_at' => now()]);

        $this->audit->record($organization, 'comms.driver_link_revoked', $link, $actor);

        return $link->refresh();
    }

    /**
     * Expire due links: links past their expiry that have
     * not had an expiry notice yet. Each one gets the
     * expiry notification once. Returns how many links
     * were notified.
     */
    public function expireDueLinks(): int
    {
        $notified = 0;
        $cutoff = now()->subDays((int) config('comms.driver_link_expiry_notice_days', 30));

        $links = DriverEvidenceLink::query()
            ->withoutGlobalScope('organization')
            ->whereNull('revoked_at')
            ->whereNull('expiry_notified_at')
            ->where('expires_at', '<', now())
            ->where('expires_at', '>', $cutoff)
            ->with('organization')
            ->get();

        foreach ($links as $link) {
            $organization = $link->organization;

            if ($organization === null) {
                continue;
            }

            $link->update(['expiry_notified_at' => now()]);

            $this->notifyLinkMembers(
                $organization,
                $link,
                null,
                NotificationType::DriverLinkExpired,
                'Driver evidence link expired',
                'The submission link for '.($link->shipment?->internal_reference ?? $link->claim?->claim_reference ?? 'a shipment').' expired on '.$link->expires_at->toDayDateTimeString().'. Create a new link if the driver still needs to submit evidence.'
            );

            $notified++;
        }

        return $notified;
    }

    /**
     * Find a driver link by its raw token. The token is
     * the credential: only the hash is ever stored.
     */
    public function linkByToken(string $rawToken): ?DriverEvidenceLink
    {
        return DriverEvidenceLink::query()
            ->where('token_hash', hash('sha256', $rawToken))
            ->first();
    }

    /**
     * Submit evidence through a driver link. The file is
     * stored on the evidence disk under a generated key
     * (the original filename is metadata only), verified
     * after the write, and recorded as an evidence file
     * with no uploader — the driver has no account. The
     * link's used count rises by one; a single-use link
     * is spent by this submission.
     */
    public function submitDriverEvidence(
        DriverEvidenceLink $link,
        UploadedFile $file,
        ?string $driverName,
        ?string $notes,
        string $documentCategory,
        ?string $description = null,
    ): DriverEvidenceSubmission {
        $organization = $link->organization;

        if (! $link->isUsable()) {
            throw ValidationException::withMessages(['link' => [
                'This submission link is '.$link->state().'. Ask the organization for a new one.',
            ]]);
        }

        // Evidence is always filed against a shipment.
        // A claim-only link resolves the shipment through
        // its claim: every claim belongs to a shipment, so
        // a claim-scoped link can accept evidence too.
        $shipment = $link->shipment ?? $link->claim?->shipment;

        if ($shipment === null) {
            throw ValidationException::withMessages(['shipment_id' => [
                'This link does not cover a shipment, so evidence cannot be filed through it.',
            ]]);
        }

        if ($shipment->isArchived()) {
            throw ValidationException::withMessages(['shipment_id' => [
                'Evidence cannot be added to an archived shipment.',
            ]]);
        }

        $disk = self::disk();
        $key = 'driver-evidence/'.$organization->getKey().'/'.$shipment->getKey().'/'.Str::uuid().'.'.$file->extension();
        $checksum = hash_file('sha256', $file->getRealPath());

        Storage::disk($disk)->putFileAs(dirname($key), $file, basename($key));

        $stored = Storage::disk($disk);

        if (! $stored->exists($key) || $stored->size($key) !== $file->getSize()) {
            $stored->delete($key);

            throw ValidationException::withMessages(['file' => [
                'The upload could not be verified. Please try again.',
            ]]);
        }

        try {
            $submission = DB::transaction(function () use ($link, $organization, $shipment, $file, $driverName, $notes, $documentCategory, $description, $key, $disk, $checksum) {
                $evidence = EvidenceFile::create([
                    'organization_id' => $organization->getKey(),
                    'shipment_id' => $shipment->getKey(),
                    'document_category' => $documentCategory,
                    'original_filename' => $file->getClientOriginalName(),
                    'storage_key' => $key,
                    'disk' => $disk,
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'checksum' => $checksum,
                    'uploaded_by' => null,
                    'description' => $description ?? 'Submitted by the driver through a submission link.',
                    'verification_status' => \App\EvidenceVerificationStatus::Pending,
                ]);

                $submission = DriverEvidenceSubmission::create([
                    'organization_id' => $organization->getKey(),
                    'driver_evidence_link_id' => $link->getKey(),
                    'shipment_id' => $shipment->getKey(),
                    'claim_id' => $link->claim_id,
                    'evidence_file_id' => $evidence->getKey(),
                    'driver_name' => $driverName ?? $link->driver_name,
                    'notes' => $notes,
                    'submitted_at' => now(),
                ]);

                $link->increment('used_count');

                return $submission;
            });
        } catch (\Throwable $e) {
            $stored->delete($key);

            throw $e;
        }

        $this->audit->record($organization, 'comms.driver_evidence_submitted', $submission, null);

        $this->notifyLinkMembers(
            $organization,
            $link,
            null,
            NotificationType::DriverLinkUsed,
            'Evidence received from a driver',
            ($submission->driver_name ?? 'A driver').' submitted '.$file->getClientOriginalName().' for shipment '.($shipment->internal_reference ?? $shipment->external_load_reference ?? 'a shipment').' through a submission link.'
        );

        $this->sendEvent(
            $organization,
            'driver_link_used',
            'Evidence received from a driver',
            ($submission->driver_name ?? 'A driver').' submitted evidence for shipment '.($shipment->internal_reference ?? $shipment->external_load_reference ?? 'a shipment').'.',
            ['driver_evidence_link_id' => (string) $link->getKey(), 'shipment_id' => (string) $shipment->getKey()],
            null,
        );

        return $submission->refresh();
    }

    /**
     * Send the driver-link email. A failed send never
     * fails the link: it is logged and notified as an
     * email workflow failure, and the link stays usable.
     */
    protected function sendDriverLinkEmail(
        Organization $organization,
        DriverEvidenceLink $link,
        User $actor,
        string $rawToken,
    ): void {
        try {
            Mail::to($link->driver_email)->send(new DriverEvidenceLinkMail($link, $rawToken));
        } catch (\Throwable $exception) {
            Log::error('Driver evidence link email failed.', [
                'organization_id' => $organization->getKey(),
                'driver_evidence_link_id' => $link->getKey(),
                'error' => $exception->getMessage(),
            ]);

            $this->notifyLinkMembers(
                $organization,
                $link,
                $actor,
                NotificationType::CommunicationEmailFailed,
                'Driver evidence link email failed',
                'The email with the submission link for '.($link->shipment?->internal_reference ?? $link->claim?->claim_reference ?? 'a shipment').' could not be sent. The link itself still works: share the link URL directly.',
                'comms-driver-link:'.$link->getKey().':email-failed:'.Str::uuid()
            );

            return;
        }

        $this->notifyLinkMembers(
            $organization,
            $link,
            $actor,
            NotificationType::CommunicationEmailSent,
            'Driver evidence link email sent',
            'The submission link was emailed to '.$link->driver_email.'.',
            'comms-driver-link:'.$link->getKey().':email-sent:'.Str::uuid()
        );
    }

    /**
     * Notify members who can manage communications about a
     * channel event. The actor is excluded, as elsewhere.
     * A repeatable event (a channel test, an email send)
     * passes its own dedupe key so each run notifies once.
     */
    protected function notifyChannel(
        Organization $organization,
        CommunicationChannel $channel,
        ?User $actor,
        NotificationType $type,
        string $title,
        string $body,
        ?string $dedupeKey = null,
    ): void {
        $members = $this->notifications->membersWithPermission(
            $organization,
            Permission::ManageCommunications,
            $actor,
        );

        $this->notifications->notify(
            $organization,
            $members,
            $type,
            $title,
            $body,
            ['communication_channel_id' => (string) $channel->getKey()],
            $dedupeKey ?? 'comms-channel:'.$channel->getKey().':'.$type->value,
            $actor,
        );
    }

    /**
     * Notify members who can manage communications about a
     * driver link event. The actor is excluded when there
     * is one. A repeatable event (an email send) passes
     * its own dedupe key so each run notifies once.
     */
    protected function notifyLinkMembers(
        Organization $organization,
        DriverEvidenceLink $link,
        ?User $actor,
        NotificationType $type,
        string $title,
        string $body,
        ?string $dedupeKey = null,
    ): void {
        $members = $this->notifications->membersWithPermission(
            $organization,
            Permission::ManageCommunications,
            $actor,
        );

        $this->notifications->notify(
            $organization,
            $members,
            $type,
            $title,
            $body,
            ['driver_evidence_link_id' => (string) $link->getKey()],
            $dedupeKey ?? 'comms-driver-link:'.$link->getKey().':'.$type->value,
            $actor,
        );
    }

    /**
     * Resolve the shipment a link covers, scoped to the
     * organization. Null when no shipment was given.
     */
    protected function resolveLinkShipment(Organization $organization, ?string $shipmentId): ?Shipment
    {
        if ($shipmentId === null) {
            return null;
        }

        $shipment = Shipment::forOrganization($organization)->whereKey($shipmentId)->first();

        if ($shipment === null) {
            throw ValidationException::withMessages(['shipment_id' => [
                'That shipment does not exist in this organization.',
            ]]);
        }

        return $shipment;
    }

    /**
     * Resolve the claim a link covers, scoped to the
     * organization. Null when no claim was given.
     */
    protected function resolveLinkClaim(Organization $organization, ?string $claimId): ?Claim
    {
        if ($claimId === null) {
            return null;
        }

        $claim = Claim::forOrganization($organization)->whereKey($claimId)->first();

        if ($claim === null) {
            throw ValidationException::withMessages(['claim_id' => [
                'That claim does not exist in this organization.',
            ]]);
        }

        return $claim;
    }
}
