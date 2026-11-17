<?php

namespace App\Http\Controllers\Api\V1;

use App\EvidenceDocumentCategory;
use App\EvidenceVerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\EvidenceFileResource;
use App\Models\EvidenceFile;
use App\Models\Organization;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EvidenceFileController extends Controller
{
    /**
     * List evidence files of the resolved organization, with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ViewEvidence->value, $organization);

        $files = $organization->evidenceFiles()
            ->with('uploader')
            ->when($request->filled('shipment_id'), fn ($query) => $query->where('shipment_id', $request->string('shipment_id')))
            ->when($request->filled('shipment_event_id'), fn ($query) => $query->where('shipment_event_id', $request->string('shipment_event_id')))
            ->when(
                $request->filled('document_category') && in_array($request->string('document_category')->toString(), EvidenceDocumentCategory::values(), true),
                fn ($query) => $query->where('document_category', $request->string('document_category'))
            )
            ->when(
                $request->filled('verification_status') && in_array($request->string('verification_status')->toString(), EvidenceVerificationStatus::values(), true),
                fn ($query) => $query->where('verification_status', $request->string('verification_status'))
            )
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request, 25));

        return response()->json([
            'data' => EvidenceFileResource::collection($files),
            'meta' => $this->paginationMeta($files),
        ]);
    }

    /**
     * Upload an evidence file for a shipment, optionally tied to an event.
     *
     * The file lands on the private disk under a generated key; the
     * original filename is metadata only and never becomes a path.
     */
    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::UploadEvidence->value, $organization);

        $validated = $request->validate([
            'shipment_id' => [
                'required', 'uuid',
                Rule::exists('shipments', 'id')->where('organization_id', $organization->getKey()),
            ],
            'shipment_event_id' => [
                'nullable', 'uuid',
                Rule::exists('shipment_events', 'id')->where('organization_id', $organization->getKey()),
            ],
            'document_category' => ['required', 'string', Rule::in(EvidenceDocumentCategory::values())],
            'description' => ['nullable', 'string', 'max:2000'],
            'file' => [
                'required', 'file',
                'max:'.config('evidence.max_size_kb'),
                'mimes:'.implode(',', config('evidence.allowed_extensions')),
            ],
        ]);

        $shipment = Shipment::forOrganization($organization->getKey())
            ->whereKey($validated['shipment_id'])
            ->firstOrFail();

        if ($shipment->isArchived()) {
            return response()->json([
                'message' => 'Evidence cannot be added to an archived shipment.',
            ], 422);
        }

        if (! empty($validated['shipment_event_id'])) {
            $event = ShipmentEvent::forOrganization($organization->getKey())
                ->whereKey($validated['shipment_event_id'])
                ->firstOrFail();

            if ($event->shipment_id !== $shipment->getKey()) {
                return response()->json([
                    'message' => 'The event does not belong to this shipment.',
                ], 422);
            }
        }

        $file = $request->file('file');
        $disk = config('evidence.disk');
        $key = 'evidence/'.$organization->getKey().'/'.$shipment->getKey().'/'.Str::uuid().'.'.$file->extension();

        Storage::disk($disk)->putFileAs(
            dirname($key),
            $file,
            basename($key)
        );

        $evidence = EvidenceFile::create([
            'organization_id' => $organization->getKey(),
            'shipment_id' => $shipment->getKey(),
            'shipment_event_id' => $validated['shipment_event_id'] ?? null,
            'document_category' => $validated['document_category'],
            'original_filename' => $file->getClientOriginalName(),
            'storage_key' => $key,
            'disk' => $disk,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
            'description' => $validated['description'] ?? null,
            'verification_status' => EvidenceVerificationStatus::Pending,
        ]);

        return response()->json([
            'data' => new EvidenceFileResource($evidence->load('uploader')),
        ], 201);
    }

    /**
     * Show one evidence record's metadata.
     */
    public function show(Request $request, EvidenceFile $evidence): JsonResponse
    {
        $organization = $this->organization($request);

        if ($evidence->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('view', $evidence);

        $evidence->load('uploader');

        return response()->json([
            'data' => new EvidenceFileResource($evidence),
        ]);
    }

    /**
     * Preview a file inline in the browser.
     *
     * Only images and PDFs are previewable: other types get a 422
     * with previewable false, and the client falls back to a
     * download. Authorization and storage checks are identical to
     * the download endpoint.
     */
    public function preview(Request $request, EvidenceFile $evidence): BinaryFileResponse|RedirectResponse|StreamedResponse|JsonResponse
    {
        $organization = $this->organization($request);

        if ($evidence->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('view', $evidence);

        if (! $evidence->isPreviewable()) {
            return response()->json([
                'message' => 'Preview is not available for this file type. Download the file instead.',
                'previewable' => false,
                'mime_type' => $evidence->mime_type,
            ], 422);
        }

        $disk = Storage::disk($evidence->disk);

        if (! $disk->exists($evidence->storage_key)) {
            Log::warning('Evidence file missing from storage.', [
                'evidence_id' => $evidence->getKey(),
                'organization_id' => $organization->getKey(),
            ]);

            abort(404, 'The file is no longer available.');
        }

        $disposition = (new ResponseHeaderBag)->makeDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $evidence->original_filename
        );

        if ($evidence->disk === 's3') {
            return redirect()->away($disk->temporaryUrl(
                $evidence->storage_key,
                now()->addMinutes(5),
                [
                    'ResponseContentDisposition' => $disposition,
                    'ResponseContentType' => $evidence->mime_type,
                ]
            ));
        }

        return $disk->response(
            $evidence->storage_key,
            $evidence->original_filename,
            [
                'Content-Type' => $evidence->mime_type,
                'Content-Disposition' => $disposition,
            ]
        );
    }

    /**
     * Download a file, or preview it inline with ?preview=1.
     *
     * Access is authorized on every request. On the local disk the
     * file streams through this response; on S3 the client is
     * redirected to a short-lived signed URL. There is no permanent
     * public URL for any evidence file.
     */
    public function download(Request $request, EvidenceFile $evidence): BinaryFileResponse|RedirectResponse|StreamedResponse
    {
        $organization = $this->organization($request);

        if ($evidence->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('view', $evidence);

        $disk = Storage::disk($evidence->disk);

        if (! $disk->exists($evidence->storage_key)) {
            Log::warning('Evidence file missing from storage.', [
                'evidence_id' => $evidence->getKey(),
                'organization_id' => $organization->getKey(),
            ]);

            abort(404, 'The file is no longer available.');
        }

        $disposition = (new ResponseHeaderBag)->makeDisposition(
            $request->boolean('preview')
                ? ResponseHeaderBag::DISPOSITION_INLINE
                : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $evidence->original_filename
        );

        if ($evidence->disk === 's3') {
            return redirect()->away($disk->temporaryUrl(
                $evidence->storage_key,
                now()->addMinutes(5),
                ['ResponseContentDisposition' => $disposition]
            ));
        }

        if ($request->boolean('preview')) {
            return $disk->response(
                $evidence->storage_key,
                $evidence->original_filename,
                ['Content-Disposition' => $disposition]
            );
        }

        return $disk->download($evidence->storage_key, $evidence->original_filename);
    }

    /**
     * Change the verification status of an evidence file.
     */
    public function verify(Request $request, EvidenceFile $evidence): JsonResponse
    {
        $organization = $this->organization($request);

        if ($evidence->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('verify', $evidence);

        $validated = $request->validate([
            'verification_status' => ['required', 'string', Rule::in(EvidenceVerificationStatus::values())],
        ]);

        $evidence->update($validated);

        return response()->json([
            'data' => new EvidenceFileResource($evidence->fresh()->load('uploader')),
        ]);
    }

    /**
     * Delete an evidence file and its stored object.
     *
     * The deletion is written to the application log. A full audit
     * trail lands in Milestone 4; until then the log line is the
     * only record of who deleted what.
     */
    public function destroy(Request $request, EvidenceFile $evidence): JsonResponse
    {
        $organization = $this->organization($request);

        if ($evidence->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('delete', $evidence);

        Log::info('Evidence file deleted.', [
            'evidence_id' => $evidence->getKey(),
            'organization_id' => $organization->getKey(),
            'shipment_id' => $evidence->shipment_id,
            'shipment_event_id' => $evidence->shipment_event_id,
            'original_filename' => $evidence->original_filename,
            'deleted_by' => $request->user()->id,
        ]);

        Storage::disk($evidence->disk)->delete($evidence->storage_key);
        $evidence->delete();

        return response()->json(['message' => 'Evidence deleted.']);
    }

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    /**
     * Page size from the request, clamped to the allowed range.
     */
    protected function perPage(Request $request, int $default): int
    {
        return max(1, min(100, (int) $request->input('per_page', $default)));
    }

    /**
     * Pagination metadata in the standard list shape.
     */
    protected function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
