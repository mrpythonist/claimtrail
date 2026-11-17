<?php

namespace App\Services;

use App\ClaimStatus;
use App\Models\ClaimReviewNote;
use App\Models\Organization;

/**
 * Guided onboarding progress for one organization.
 *
 * Step completion is derived live from the organization's data, so
 * there is nothing to keep in sync: the moment the team invites a
 * member or creates their first shipment, that step is done. Only
 * the dismissal is stored (organizations.onboarding_dismissed_at),
 * because it is a choice rather than a fact about the data.
 */
class OnboardingService
{
    /**
     * The onboarding steps, in the order a new team meets them.
     *
     * @return array<int, array{key: string, label: string, description: string, href: string}>
     */
    public static function stepDefinitions(): array
    {
        return [
            [
                'key' => 'create_organization',
                'label' => 'Create your organization',
                'description' => 'Your workspace for shipments, claims, and evidence.',
                'href' => '/settings/organization',
            ],
            [
                'key' => 'invite_member',
                'label' => 'Invite a team member',
                'description' => 'Dispatchers and billing staff work in the same organization.',
                'href' => '/settings/members',
            ],
            [
                'key' => 'create_shipment',
                'label' => 'Add your first shipment',
                'description' => 'Record the load, broker, appointments, and rate.',
                'href' => '/shipments/new',
            ],
            [
                'key' => 'add_event',
                'label' => 'Record a detention or layover event',
                'description' => 'Events are what a claim is built on.',
                'href' => '/shipments',
            ],
            [
                'key' => 'upload_evidence',
                'label' => 'Upload supporting evidence',
                'description' => 'Bills of lading, rate confirmations, and photos prove the claim.',
                'href' => '/evidence',
            ],
            [
                'key' => 'create_claim',
                'label' => 'Create your first claim',
                'description' => 'Turn an event into a claim with a requested amount.',
                'href' => '/claims/new',
            ],
            [
                'key' => 'review_claim',
                'label' => 'Review a claim',
                'description' => 'Check the evidence, leave a reviewer note, and mark it ready to submit.',
                'href' => '/claims',
            ],
        ];
    }

    /**
     * The progress payload for the organization.
     *
     * @return array{steps: array<int, array{key: string, label: string, description: string, href: string, completed: bool}>, completed_count: int, total_count: int, all_completed: bool, dismissed: bool}
     */
    public function progress(Organization $organization): array
    {
        $completed = $this->completedSteps($organization);

        $steps = array_map(
            fn (array $step) => array_merge($step, [
                'completed' => in_array($step['key'], $completed, true),
            ]),
            self::stepDefinitions()
        );

        $completedCount = count($completed);

        return [
            'steps' => $steps,
            'completed_count' => $completedCount,
            'total_count' => count($steps),
            'all_completed' => $completedCount === count($steps),
            'dismissed' => $organization->onboarding_dismissed_at !== null,
        ];
    }

    /**
     * Which step keys are complete for this organization.
     *
     * @return array<int, string>
     */
    protected function completedSteps(Organization $organization): array
    {
        $done = ['create_organization'];

        // A second member, or an invitation out to one, means the
        // team step is done even before anyone accepts.
        if ($organization->members()->count() > 1 || $organization->invitations()->exists()) {
            $done[] = 'invite_member';
        }

        if ($organization->shipments()->exists()) {
            $done[] = 'create_shipment';
        }

        if ($organization->shipmentEvents()->exists()) {
            $done[] = 'add_event';
        }

        if ($organization->evidenceFiles()->exists()) {
            $done[] = 'upload_evidence';
        }

        if ($organization->claims()->exists()) {
            $done[] = 'create_claim';
        }

        // A claim is "reviewed" once one has reached ready to submit
        // or beyond, or a reviewer has left a note on any claim.
        $reviewed = $organization->claims()
            ->whereIn('status', [
                ClaimStatus::ReadyToSubmit->value,
                ClaimStatus::Submitted->value,
                ClaimStatus::Accepted->value,
                ClaimStatus::PartiallyAccepted->value,
                ClaimStatus::Rejected->value,
                ClaimStatus::Paid->value,
                ClaimStatus::Closed->value,
            ])
            ->exists()
            || ClaimReviewNote::forOrganization($organization->getKey())->exists();

        if ($reviewed) {
            $done[] = 'review_claim';
        }

        return $done;
    }
}
