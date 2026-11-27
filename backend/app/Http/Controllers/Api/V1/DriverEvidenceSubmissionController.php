<?php

namespace App\Http\Controllers\Api\V1;

use App\EvidenceDocumentCategory;
use App\Http\Controllers\Controller;
use App\Services\CommunicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Public driver evidence submission (v1.3.3).
 *
 * No authentication: the token in the URL is the
 * credential, exactly like the billing portal token and
 * the invitation token. The link is scoped to one
 * shipment or claim, expires, and is single-use by
 * default; every access enforces that. The driver sees
 * only the organization name and the shipment and claim
 * references the link covers — nothing else.
 */
class DriverEvidenceSubmissionController extends Controller
{
    public function __construct(
        protected CommunicationService $comms,
    ) {}

    /**
     * What a driver link covers, for the submission page.
     * A link that cannot accept a submission answers 404,
     * the same as a link that does not exist: the token
     * alone must not reveal whether a link is real.
     */
    public function show(Request $request, string $token): JsonResponse
    {
        $link = $this->comms->linkByToken($token);

        if ($link === null || ! $link->isUsable()) {
            return response()->json(['message' => 'This submission link is invalid, expired, revoked, or already used.'], 404);
        }

        return response()->json([
            'data' => $this->comms->publicLinkPayload($link->load(['shipment', 'claim'])),
        ]);
    }

    /**
     * Submit one evidence file through a driver link. The
     * file lands on the private evidence disk under a
     * generated key; the original filename is metadata
     * only. A single-use link is spent by this submission.
     */
    public function submit(Request $request, string $token): JsonResponse
    {
        $link = $this->comms->linkByToken($token);

        if ($link === null || ! $link->isUsable()) {
            return response()->json(['message' => 'This submission link is invalid, expired, revoked, or already used.'], 404);
        }

        $validated = $request->validate([
            'driver_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'document_category' => ['required', 'string', Rule::in(EvidenceDocumentCategory::values())],
            'description' => ['nullable', 'string', 'max:2000'],
            'file' => [
                'required', 'file',
                'max:'.config('evidence.max_size_kb'),
                'mimes:'.implode(',', config('evidence.allowed_extensions')),
            ],
        ]);

        $submission = $this->comms->submitDriverEvidence(
            $link,
            $request->file('file'),
            $validated['driver_name'] ?? null,
            $validated['notes'] ?? null,
            $validated['document_category'],
            $validated['description'] ?? null,
        );

        return response()->json([
            'data' => $this->comms->submissionPayload($submission),
            'message' => 'Evidence submitted. Thank you.',
        ], 201);
    }
}
