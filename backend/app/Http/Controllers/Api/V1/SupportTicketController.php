<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\SupportTicket;
use App\Permission;
use App\Services\SupportTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Customer support tickets for one organization (v1.2.3).
 *
 * Any member may open a ticket and reply on it. Triage, status
 * changes, and internal notes are for the organization's owner
 * and admins (manage-support). Ticket bodies and messages are
 * never written to the audit log; status changes are audit rows
 * without the message text.
 */
class SupportTicketController extends Controller
{
    public function __construct(
        protected SupportTicketService $tickets,
    ) {}

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    /**
     * Whether the current user may manage support for the
     * organization (owner and admin).
     */
    protected function canManageSupport(Request $request): bool
    {
        return $request->user()->hasPermissionIn(
            $this->organization($request),
            Permission::ManageSupport,
        );
    }

    /**
     * Page size from the request, clamped to the allowed range.
     */
    protected function perPage(Request $request, int $default): int
    {
        return max(1, min(100, (int) $request->input('per_page', $default)));
    }

    /**
     * Tickets of the organization, newest first. Filters: status,
     * category.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $includeInternal = $this->canManageSupport($request);

        $query = SupportTicket::forOrganization($organization)
            ->with(['requester'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        $tickets = $query->paginate($this->perPage($request, 25));

        return response()->json([
            'data' => $tickets->getCollection()->map(
                fn (SupportTicket $ticket) => $this->tickets->payload($ticket, $includeInternal),
            )->all(),
            'meta' => [
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
                'per_page' => $tickets->perPage(),
                'total' => $tickets->total(),
            ],
        ]);
    }

    /**
     * Open a ticket with its first message.
     */
    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'category' => ['sometimes', 'string', 'in:'.implode(',', SupportTicket::categories())],
            'priority' => ['sometimes', 'string', 'in:'.implode(',', SupportTicket::priorities())],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $ticket = $this->tickets->create(
            $organization,
            $request->user(),
            $validated['subject'],
            $validated['category'] ?? SupportTicket::CATEGORY_OTHER,
            $validated['message'],
            $validated['priority'] ?? SupportTicket::PRIORITY_NORMAL,
        );

        return response()->json([
            'data' => $this->tickets->payload($ticket, $this->canManageSupport($request)),
        ], 201);
    }

    /**
     * One ticket with its messages. Internal notes are included
     * only for owner and admin.
     */
    public function show(Request $request, SupportTicket $ticket): JsonResponse
    {
        $organization = $this->organization($request);

        if ($ticket->organization_id !== $organization->getKey()) {
            abort(404);
        }

        return response()->json([
            'data' => $this->tickets->payload($ticket, $this->canManageSupport($request)),
        ]);
    }

    /**
     * Add a message to a ticket. An internal note needs
     * manage-support; a public reply from anyone reopens a
     * resolved ticket.
     */
    public function reply(Request $request, SupportTicket $ticket): JsonResponse
    {
        $organization = $this->organization($request);

        if ($ticket->organization_id !== $organization->getKey()) {
            abort(404);
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'internal' => ['sometimes', 'boolean'],
        ]);

        if ($validated['internal'] ?? false) {
            Gate::authorize(Permission::ManageSupport->value, $organization);
        }

        $message = $this->tickets->reply(
            $organization,
            $ticket,
            $request->user(),
            $validated['body'],
            $validated['internal'] ?? false,
        );

        return response()->json([
            'data' => [
                'id' => $message->getKey(),
                'body' => $message->body,
                'internal' => $message->internal,
                'created_at' => $message->created_at->toIso8601String(),
                'ticket' => $this->tickets->payload($ticket->fresh(), $this->canManageSupport($request)),
            ],
        ], 201);
    }

    /**
     * Change a ticket's status. Owner and admin only.
     */
    public function setStatus(Request $request, SupportTicket $ticket): JsonResponse
    {
        $organization = $this->organization($request);

        if ($ticket->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageSupport->value, $organization);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', SupportTicket::statuses())],
        ]);

        $updated = $this->tickets->setStatus(
            $organization,
            $ticket,
            $validated['status'],
            $request->user(),
        );

        return response()->json([
            'data' => $this->tickets->payload($updated, true),
        ]);
    }
}
