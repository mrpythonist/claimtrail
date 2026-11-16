<?php

namespace App\Services;

use App\Jobs\SendNotificationEmail;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\User;
use App\NotificationType;
use App\Permission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Creates in-app notifications and queues notification emails.
 *
 * Every recipient's preferences are respected per channel: in-app
 * off means no row, email off means no mail. A dedupe key makes a
 * notification idempotent per user, so a repeated job run (the
 * hourly deadline refresh, a retried queue job) cannot notify
 * twice about the same event. The email for a deduped event is
 * only queued when the in-app row is new, or, when the member has
 * in-app off, when a 24-hour cache lock is acquired.
 */
class NotificationService
{
    /**
     * Active members of an organization who hold a permission,
     * optionally excluding one user (usually the actor).
     *
     * @return Collection<int, User>
     */
    public function membersWithPermission(
        Organization $organization,
        Permission $permission,
        ?User $exclude = null,
    ): Collection {
        return $organization->activeMembers()
            ->with('user')
            ->get()
            ->map(fn ($member) => $member->user)
            ->filter(fn (?User $user) => $user !== null)
            ->filter(fn (User $user) => $exclude === null || $user->getKey() !== $exclude->getKey())
            ->filter(fn (User $user) => $user->hasPermissionIn($organization, $permission))
            ->values();
    }

    /**
     * Notify a set of users about an event.
     *
     * @param  iterable<int, User>  $users
     * @param  array<string, mixed>  $data  entity links (claim_id and friends)
     * @param  string|null  $dedupeKey  when set, one row per user per key
     */
    public function notify(
        Organization $organization,
        iterable $users,
        NotificationType $type,
        string $title,
        string $body,
        array $data = [],
        ?string $dedupeKey = null,
        ?User $excludeActor = null,
        bool $requireMembership = true,
        bool $sendEmail = true,
    ): void {
        foreach ($users as $user) {
            if ($excludeActor !== null && $user->getKey() === $excludeActor->getKey()) {
                continue;
            }

            if ($requireMembership && ! $user->isActiveMemberOf($organization)) {
                continue;
            }

            $preference = $this->preferenceFor($organization, $user, $type);
            $notification = null;
            $isNew = true;

            if ($preference['in_app']) {
                if ($dedupeKey !== null) {
                    $notification = Notification::firstOrCreate(
                        [
                            'organization_id' => $organization->getKey(),
                            'user_id' => $user->getKey(),
                            'dedupe_key' => $dedupeKey,
                        ],
                        [
                            'type' => $type,
                            'title' => $title,
                            'body' => $body,
                            'data' => $data,
                        ],
                    );
                    $isNew = $notification->wasRecentlyCreated;
                } else {
                    $notification = Notification::create([
                        'organization_id' => $organization->getKey(),
                        'user_id' => $user->getKey(),
                        'type' => $type,
                        'title' => $title,
                        'body' => $body,
                        'data' => $data,
                        'dedupe_key' => null,
                    ]);
                }
            }

            if (! $sendEmail || ! $preference['email']) {
                continue;
            }

            // A deduped event emails only once: when the in-app row
            // is new, or (in-app off, so there is no row) when the
            // 24-hour email lock is acquired.
            if ($dedupeKey !== null && $notification !== null && ! $isNew) {
                continue;
            }

            if ($dedupeKey !== null && $notification === null) {
                $locked = Cache::add(
                    'notification-email:'.$organization->getKey().':'.$user->getKey().':'.$dedupeKey,
                    true,
                    now()->addDay(),
                );

                if (! $locked) {
                    continue;
                }
            }

            SendNotificationEmail::dispatch(
                $user->getKey(),
                (string) $organization->getKey(),
                $type->value,
                $title,
                $body,
                $data,
                $notification?->getKey(),
            );
        }
    }

    /**
     * Channel preferences for a user and event type. No stored row
     * means the defaults: both channels on.
     *
     * @return array{in_app: bool, email: bool}
     */
    public function preferenceFor(Organization $organization, User $user, NotificationType $type): array
    {
        $row = NotificationPreference::forOrganization($organization->getKey())
            ->where('user_id', $user->getKey())
            ->where('event_type', $type->value)
            ->first();

        if ($row === null) {
            return ['in_app' => true, 'email' => true];
        }

        return ['in_app' => $row->in_app, 'email' => $row->email];
    }

    /**
     * Every event type with the user's current settings, defaults
     * included for types with no stored row.
     *
     * @return array<int, array{event_type: string, label: string, in_app: bool, email: bool}>
     */
    public function preferencesFor(Organization $organization, User $user): array
    {
        $rows = NotificationPreference::forOrganization($organization->getKey())
            ->where('user_id', $user->getKey())
            ->get()
            ->keyBy(fn (NotificationPreference $row) => $row->event_type->value);

        return array_map(function (NotificationType $type) use ($rows) {
            $row = $rows->get($type->value);

            return [
                'event_type' => $type->value,
                'label' => $type->label(),
                'in_app' => $row?->in_app ?? true,
                'email' => $row?->email ?? true,
            ];
        }, NotificationType::cases());
    }
}
