<?php

namespace App\Services;

use App\EvidenceDocumentCategory;
use App\Models\EvidenceFile;
use App\Models\InboundEmail;
use App\Models\InboundEmailAddress;
use App\Models\Organization;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Organization inbound addresses and manual matching (v1.3.0).
 *
 * Provisioning: every organization gets one address when it is
 * created (OrganizationController calls provisionDefaultAddress).
 * Organizations created before v1.3.0 have none, so the address
 * list endpoint calls ensureDefaultAddress, which creates one
 * when the organization has no active address. Both paths use
 * the same generator, so the shape is identical either way.
 *
 * Addresses look like {org-slug}-{random6}@{domain}, where the
 * domain comes from config/email_intake.php. The random suffix
 * keeps the local part unique even when two organizations share
 * a slug stem.
 *
 * Matching: when an email is matched to a shipment, each of its
 * attachments becomes an EvidenceFile row on that shipment with
 * category "other" and a description naming the source email.
 * There is no email category in EvidenceDocumentCategory, and
 * inventing one would change the evidence vocabulary; "other"
 * with the source in the description is the documented mapping.
 */
class EmailIntakeService
{
    public function __construct(
        protected AuditService $audit,
    ) {}

    /**
     * The configured intake domain.
     */
    public function domain(): string
    {
        return (string) config('email_intake.domain');
    }

    /**
     * Full address for a local part.
     */
    public function fullAddress(string $localPart): string
    {
        return $localPart.'@'.$this->domain();
    }

    /**
     * Create the organization's default address when it has no
     * active one. Returns the existing active address otherwise.
     */
    public function ensureDefaultAddress(Organization $organization): InboundEmailAddress
    {
        $existing = InboundEmailAddress::forOrganization($organization)
            ->where('active', true)
            ->orderBy('created_at')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->createAddress($organization, null);
    }

    /**
     * Create the default address for a newly created
     * organization. A no-op when one already exists, so it is
     * safe to call twice.
     */
    public function provisionDefaultAddress(Organization $organization): InboundEmailAddress
    {
        return $this->ensureDefaultAddress($organization);
    }

    /**
     * Create an additional address for the organization.
     */
    public function createAddress(Organization $organization, ?string $label): InboundEmailAddress
    {
        return InboundEmailAddress::create([
            'organization_id' => $organization->getKey(),
            'local_part' => $this->generateLocalPart($organization),
            'label' => $label,
            'active' => true,
        ]);
    }

    /**
     * Replace an address's local part with a fresh random one.
     * The old address stops working immediately.
     */
    public function regenerate(InboundEmailAddress $address): InboundEmailAddress
    {
        $address->update([
            'local_part' => $this->generateLocalPart($address->organization),
        ]);

        return $address->fresh();
    }

    /**
     * Deactivate an address. Mail to it is no longer accepted;
     * the row stays for history. At least one active address
     * must remain: deactivating the last active address is
     * refused, so an organization can never lose its inbox.
     *
     * @throws ValidationException when it is the last active address.
     */
    public function deactivate(InboundEmailAddress $address): InboundEmailAddress
    {
        $activeCount = InboundEmailAddress::forOrganization($address->organization)
            ->where('active', true)
            ->count();

        if ($activeCount <= 1) {
            throw ValidationException::withMessages([
                'address' => 'An organization must keep at least one active inbound address.',
            ]);
        }

        $address->update(['active' => false]);

        return $address->fresh();
    }

    /**
     * One address as the API returns it.
     *
     * @return array<string, mixed>
     */
    public function addressPayload(InboundEmailAddress $address): array
    {
        return [
            'id' => $address->getKey(),
            'local_part' => $address->local_part,
            'address' => $address->fullAddress(),
            'label' => $address->label,
            'active' => $address->active,
            'created_at' => $address->created_at->toIso8601String(),
            'updated_at' => $address->updated_at->toIso8601String(),
        ];
    }

    /**
     * One inbound email as the API returns it, with its
     * attachments and the matched shipment's reference.
     *
     * @return array<string, mixed>
     */
    public function emailPayload(InboundEmail $email): array
    {
        $email->loadMissing(['attachments', 'matchedShipment', 'address']);

        return [
            'id' => $email->getKey(),
            'from' => $email->from,
            'to' => $email->to,
            'subject' => $email->subject,
            'text_body' => $email->text_body,
            'message_id' => $email->message_id,
            'status' => $email->status,
            'matched_shipment_id' => $email->matched_shipment_id,
            'matched_shipment' => $email->matchedShipment !== null ? [
                'id' => $email->matchedShipment->getKey(),
                'internal_reference' => $email->matchedShipment->internal_reference,
                'external_load_reference' => $email->matchedShipment->external_load_reference,
                'broker_or_customer_name' => $email->matchedShipment->broker_or_customer_name,
            ] : null,
            'inbound_email_address_id' => $email->inbound_email_address_id,
            'extracted_references' => $email->extracted_references ?? [],
            'match_reason' => $email->match_reason,
            'match_confidence' => $email->match_confidence,
            'dismissed_reason' => $email->dismissed_reason,
            'processing_notes' => $email->processing_notes,
            'created_at' => $email->created_at->toIso8601String(),
            'updated_at' => $email->updated_at->toIso8601String(),
            'attachments' => $email->attachments->map(fn ($attachment) => [
                'id' => $attachment->getKey(),
                'filename' => $attachment->filename,
                'mime_type' => $attachment->mime_type,
                'size_bytes' => $attachment->size_bytes,
                'evidence_file_id' => $attachment->evidence_file_id,
            ])->all(),
        ];
    }

    /**
     * Manually match an email to a shipment: link the email and
     * turn its attachments into evidence on that shipment.
     *
     * @throws ValidationException when the email is dismissed.
     */
    public function matchEmail(
        Organization $organization,
        InboundEmail $email,
        Shipment $shipment,
        ?User $actor = null,
        string $reason = 'manual match',
        string $confidence = 'high',
    ): InboundEmail {
        if ($email->status === InboundEmail::STATUS_DISMISSED) {
            throw ValidationException::withMessages([
                'email' => 'A dismissed email cannot be matched. It was reviewed and set aside.',
            ]);
        }

        $before = $email->status;

        DB::transaction(function () use ($email, $shipment, $actor) {
            $email->update([
                'status' => InboundEmail::STATUS_MATCHED,
                'matched_shipment_id' => $shipment->getKey(),
                'dismissed_reason' => null,
            ]);

            $this->linkAttachmentsAsEvidence($email->fresh(), $shipment, $actor);
        });

        $this->audit->record(
            $organization,
            'email_intake.manually_matched',
            $email->fresh(),
            $actor,
            ['status' => $before],
            [
                'status' => InboundEmail::STATUS_MATCHED,
                'matched_shipment_id' => (string) $shipment->getKey(),
                'match_reason' => $reason,
            ],
        );

        return $email->fresh();
    }

    /**
     * Unmatch an email: clear the shipment link and return it
     * to unmatched, keeping its attachments as evidence on the
     * old shipment (evidence is never deleted by unmatching;
     * the files stay where they were filed).
     */
    public function unmatchEmail(
        Organization $organization,
        InboundEmail $email,
        ?User $actor = null,
        string $reason = 'manually unmatched',
    ): InboundEmail {
        $before = $email->status;
        $previousShipmentId = $email->matched_shipment_id;

        $email->update([
            'status' => InboundEmail::STATUS_UNMATCHED,
            'matched_shipment_id' => null,
            'match_reason' => $reason,
            'match_confidence' => null,
        ]);

        $this->audit->record(
            $organization,
            'email_intake.unmatched',
            $email->fresh(),
            $actor,
            ['status' => $before, 'matched_shipment_id' => $previousShipmentId !== null ? (string) $previousShipmentId : null],
            ['status' => InboundEmail::STATUS_UNMATCHED],
        );

        return $email->fresh();
    }

    /**
     * Dismiss an email: a member reviewed it and set it aside.
     * A dismissed email takes no further matching until it is
     * matched again through the match endpoint, which clears
     * the dismissal.
     *
     * @throws ValidationException when the email is already matched.
     */
    public function dismissEmail(
        Organization $organization,
        InboundEmail $email,
        ?User $actor = null,
        ?string $reason = null,
    ): InboundEmail {
        if ($email->status === InboundEmail::STATUS_MATCHED) {
            throw ValidationException::withMessages([
                'email' => 'A matched email cannot be dismissed. Unmatch it first.',
            ]);
        }

        $before = $email->status;

        $email->update([
            'status' => InboundEmail::STATUS_DISMISSED,
            'dismissed_reason' => $reason,
        ]);

        $this->audit->record(
            $organization,
            'email_intake.dismissed',
            $email->fresh(),
            $actor,
            ['status' => $before],
            ['status' => InboundEmail::STATUS_DISMISSED],
        );

        return $email->fresh();
    }

    /**
     * Create an EvidenceFile row for each attachment that does
     * not have one yet. The stored object is reused, never
     * copied: the evidence row points at the same storage path.
     * Category is "other" with the source email in the
     * description (see the class docblock).
     */
    public function linkAttachmentsAsEvidence(
        InboundEmail $email,
        Shipment $shipment,
        ?User $actor = null,
    ): void {
        $email->loadMissing('attachments');

        foreach ($email->attachments as $attachment) {
            if ($attachment->evidence_file_id !== null) {
                continue;
            }

            $evidence = EvidenceFile::create([
                'organization_id' => $email->organization_id,
                'shipment_id' => $shipment->getKey(),
                'document_category' => EvidenceDocumentCategory::Other,
                'original_filename' => $attachment->filename,
                'storage_key' => $attachment->storage_path,
                'disk' => config('evidence.disk'),
                'mime_type' => $attachment->mime_type,
                'file_size' => $attachment->size_bytes,
                'checksum' => $attachment->checksum,
                'uploaded_by' => $actor?->getKey(),
                'description' => 'Received by email from '.$email->from.' on '.$email->created_at->toDateTimeString().', message '.$email->message_id.'.',
            ]);

            $attachment->update(['evidence_file_id' => $evidence->getKey()]);
        }
    }

    /**
     * A fresh local part: the organization slug, trimmed to
     * keep the address short, plus a random six-character
     * suffix. Retried on collision: local_part is unique.
     */
    protected function generateLocalPart(Organization $organization): string
    {
        $stem = Str::slug($organization->slug ?? $organization->name);
        $stem = substr($stem !== '' ? $stem : 'org', 0, 40);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $localPart = $stem.'-'.Str::lower(Str::random(6));

            if (! InboundEmailAddress::withoutGlobalScope('organization')->where('local_part', $localPart)->exists()) {
                return $localPart;
            }
        }

        return $stem.'-'.Str::lower(Str::random(12));
    }
}
