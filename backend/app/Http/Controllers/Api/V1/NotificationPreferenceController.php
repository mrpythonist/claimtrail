<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Models\Organization;
use App\NotificationType;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The signed-in member's notification channel preferences for the
 * resolved organization.
 *
 * The list always covers every event type, with defaults (both
 * channels on) for types the member has never changed. Updates
 * upsert one row per event type.
 */
class NotificationPreferenceController extends Controller
{
    /**
     * List every event type with the member's current settings.
     */
    public function index(Request $request, NotificationService $notifications): JsonResponse
    {
        $organization = $this->organization($request);

        return response()->json([
            'data' => $notifications->preferencesFor($organization, $request->user()),
        ]);
    }

    /**
     * Save channel preferences for one or more event types.
     */
    public function update(Request $request, NotificationService $notifications): JsonResponse
    {
        $organization = $this->organization($request);

        $validated = $request->validate([
            'preferences' => ['required', 'array', 'min:1'],
            'preferences.*.event_type' => ['required', 'string', Rule::in(NotificationType::values())],
            'preferences.*.in_app' => ['required', 'boolean'],
            'preferences.*.email' => ['required', 'boolean'],
        ]);

        foreach ($validated['preferences'] as $preference) {
            NotificationPreference::updateOrCreate(
                [
                    'organization_id' => $organization->getKey(),
                    'user_id' => $request->user()->getKey(),
                    'event_type' => $preference['event_type'],
                ],
                [
                    'in_app' => $preference['in_app'],
                    'email' => $preference['email'],
                ],
            );
        }

        return response()->json([
            'data' => $notifications->preferencesFor($organization, $request->user()),
        ]);
    }

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }
}
