<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in member's own notifications for the resolved
 * organization.
 *
 * Notifications are created by NotificationService, never by these
 * endpoints. A member can only read their own rows: a notification
 * belonging to someone else, or to another organization, is a 404.
 */
class NotificationController extends Controller
{
    /**
     * List the member's notifications, newest first. The unread
     * filter (?unread=1) shows only unread rows.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $notifications = Notification::forOrganization($organization->getKey())
            ->forUser($request->user())
            ->when($request->boolean('unread'), fn ($query) => $query->unread())
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request, 25));

        return response()->json([
            'data' => NotificationResource::collection($notifications),
            'meta' => array_merge($this->paginationMeta($notifications), [
                'unread_count' => $this->countUnread($organization, $request->user()->getKey()),
            ]),
        ]);
    }

    /**
     * The member's unread count, for the badge in the app shell.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        return response()->json([
            'data' => [
                'unread_count' => $this->countUnread($organization, $request->user()->getKey()),
            ],
        ]);
    }

    /**
     * Mark one notification read.
     */
    public function read(Request $request, Notification $notification): JsonResponse
    {
        $organization = $this->organization($request);

        if ($notification->organization_id !== $organization->getKey()
            || $notification->user_id !== $request->user()->getKey()) {
            abort(404);
        }

        $notification->markAsRead();

        return response()->json([
            'data' => new NotificationResource($notification->fresh()),
        ]);
    }

    /**
     * Mark every unread notification read.
     */
    public function readAll(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $count = Notification::forOrganization($organization->getKey())
            ->forUser($request->user())
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json([
            'data' => ['marked_read' => $count],
        ]);
    }

    /**
     * The member's unread count in this organization.
     */
    protected function countUnread(Organization $organization, int $userId): int
    {
        return Notification::forOrganization($organization->getKey())
            ->forUser($userId)
            ->unread()
            ->count();
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
