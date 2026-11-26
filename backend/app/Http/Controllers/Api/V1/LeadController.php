<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lead capture for the public marketing site (v1.2.2).
 *
 * The demo request form posts here. Leads are not tenant-scoped:
 * the person has no account yet. The operator reviews them with
 * `php artisan leads:list`; there is no admin UI for them yet.
 *
 * The endpoint is idempotent by email: a second request from the
 * same email within 90 days updates the existing lead instead of
 * creating a duplicate, and returns it with 200. The response never
 * reveals whether the email was already known beyond what the
 * submitter already knows: both paths return the lead.
 */
class LeadController extends Controller
{
    /**
     * Store a demo request (or another marketing form submission).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'fleet_size' => ['nullable', 'string', 'max:50'],
            'message' => ['nullable', 'string', 'max:2000'],
            'source' => ['sometimes', 'string', Rule::in(Lead::sources())],
        ]);

        $source = $validated['source'] ?? Lead::SOURCE_DEMO_REQUEST;

        $existing = Lead::where('email', $validated['email'])
            ->where('source', $source)
            ->where('created_at', '>=', now()->subDays(90))
            ->latest()
            ->first();

        if ($existing !== null) {
            $existing->update([
                'name' => $validated['name'],
                'company' => $validated['company'] ?? $existing->company,
                'fleet_size' => $validated['fleet_size'] ?? $existing->fleet_size,
                'message' => $validated['message'] ?? $existing->message,
            ]);

            return response()->json(['data' => $this->payload($existing)], 200);
        }

        $lead = Lead::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'company' => $validated['company'] ?? null,
            'fleet_size' => $validated['fleet_size'] ?? null,
            'message' => $validated['message'] ?? null,
            'source' => $source,
            'status' => Lead::STATUS_NEW,
        ]);

        return response()->json(['data' => $this->payload($lead)], 201);
    }

    /**
     * The lead as the API returns it: the fields the submitter
     * gave, plus its status. No internal notes are exposed.
     *
     * @return array<string, mixed>
     */
    private function payload(Lead $lead): array
    {
        return [
            'id' => $lead->id,
            'name' => $lead->name,
            'email' => $lead->email,
            'company' => $lead->company,
            'fleet_size' => $lead->fleet_size,
            'message' => $lead->message,
            'source' => $lead->source,
            'status' => $lead->status,
            'created_at' => $lead->created_at?->toIso8601String(),
        ];
    }
}
