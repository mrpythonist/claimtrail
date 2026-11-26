<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InboundEmail;
use App\Models\InboundEmailAddress;
use App\Services\AuditService;
use App\Services\EntitlementService;
use App\Services\InboundEmailProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Inbound email webhook (v1.3.0).
 *
 * Public: the provider is not signed in. Authentication is the
 * HMAC-SHA256 signature over the raw body, sent as the
 * X-Email-Intake-Signature header ("sha256=<hex>"), verified
 * against config email_intake.webhook_secret. The payload is
 * the normalized JSON an inbound parse provider sends (from,
 * to, subject, text and html bodies, message_id, attachments
 * as base64 with filename and MIME type). No real provider is
 * wired yet; see docs/EMAIL-INTAKE.md.
 *
 * Unknown `to` addresses return 422 with a generic message:
 * the response never says whether an address exists. A
 * repeated message_id for the same organization is a
 * redelivery: the stored email is returned with
 * duplicate: true and is not processed twice.
 */
class EmailIntakeWebhookController extends Controller
{
    public function __construct(
        protected InboundEmailProcessor $processor,
        protected AuditService $audit,
        protected EntitlementService $entitlements,
    ) {}

    /**
     * Receive one normalized inbound email.
     */
    public function inbound(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = (string) $request->header(config('email_intake.signature_header'), '');

        if (! $this->verifySignature($payload, $signature)) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        $data = json_decode($payload, true);

        if (! is_array($data)) {
            return response()->json(['message' => 'Invalid webhook payload.'], 400);
        }

        $validator = Validator::make($data, [
            'from' => ['required', 'string', 'max:255'],
            'to' => ['required', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:1000'],
            'text_body' => ['nullable', 'string'],
            'html_body' => ['nullable', 'string'],
            'message_id' => ['required', 'string', 'max:255'],
            'attachments' => ['sometimes', 'array', 'max:20'],
            'attachments.*.filename' => ['required_with:attachments', 'string', 'max:255'],
            'attachments.*.mime_type' => ['required_with:attachments', 'string', 'max:100'],
            'attachments.*.content_base64' => ['required_with:attachments', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $validated = $validator->validated();

        // Resolve the recipient without leaking which addresses
        // exist: an unknown or inactive address gets the same
        // generic 422 as any other unprocessable payload.
        $localPart = strtolower(trim(explode('@', $validated['to'])[0] ?? ''));
        $address = InboundEmailAddress::withoutGlobalScope('organization')
            ->where('local_part', $localPart)
            ->where('active', true)
            ->first();

        if ($address === null) {
            return response()->json([
                'message' => 'The recipient address could not be accepted.',
            ], 422);
        }

        $organizationId = $address->organization_id;

        // Idempotent redelivery: the same message_id for the
        // same organization returns the stored email.
        $existing = InboundEmail::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('message_id', $validated['message_id'])
            ->first();

        if ($existing !== null) {
            return response()->json([
                'data' => [
                    'id' => $existing->getKey(),
                    'status' => $existing->status,
                    'duplicate' => true,
                ],
            ]);
        }

        // Attachment validation runs before anything is stored:
        // an attachment that breaks the evidence limits rejects
        // the whole delivery with the reason named.
        $attachments = $validated['attachments'] ?? [];
        $totalBytes = 0;

        foreach ($attachments as $attachment) {
            $bytes = base64_decode($attachment['content_base64'], true);

            if ($bytes === false) {
                return response()->json([
                    'message' => "Attachment {$attachment['filename']} is not valid base64.",
                ], 422);
            }

            $reason = $this->processor->validateAttachment(
                $attachment['filename'],
                $attachment['mime_type'],
                $bytes,
            );

            if ($reason !== null) {
                return response()->json(['message' => $reason], 422);
            }

            $totalBytes += strlen($bytes);
        }

        // Plan limits (v1.2.1): a delivery whose attachments
        // would take the organization past its storage cap is
        // refused before anything is stored, with a 402 naming
        // the plan that removes the limit. Nothing is stored, so
        // a provider retry after an upgrade is safe.
        $this->entitlements->authorizeUpload($address->organization, $totalBytes);

        $email = InboundEmail::create([
            'organization_id' => $organizationId,
            'inbound_email_address_id' => $address->getKey(),
            'from' => $validated['from'],
            'to' => $validated['to'],
            'subject' => $validated['subject'] ?? null,
            'text_body' => $validated['text_body'] ?? null,
            'html_body' => $validated['html_body'] ?? null,
            'message_id' => $validated['message_id'],
            'status' => InboundEmail::STATUS_RECEIVED,
        ]);

        $organization = $address->organization;

        $this->audit->record(
            $organization,
            'email_intake.received',
            $email,
            null,
            null,
            ['status' => InboundEmail::STATUS_RECEIVED, 'message_id' => $email->message_id],
        );

        $this->processor->storeAttachments($email, $attachments);
        $processed = $this->processor->process($email->fresh());

        return response()->json([
            'data' => [
                'id' => $processed->getKey(),
                'status' => $processed->status,
                'matched_shipment_id' => $processed->matched_shipment_id,
                'duplicate' => false,
            ],
        ], 201);
    }

    /**
     * HMAC-SHA256 over the raw body, "sha256=<hex>". An empty
     * secret never verifies: with no provider configured, no
     * delivery is accepted.
     */
    protected function verifySignature(string $payload, string $signature): bool
    {
        $secret = (string) config('email_intake.webhook_secret');

        if ($signature === '' || $secret === '') {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $payload, $secret), $signature);
    }
}
