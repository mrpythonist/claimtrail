<?php

namespace Tests\Feature;

use App\Models\ClaimReviewNote;
use App\Models\EvidenceRequirement;
use App\Models\GeneratedDocument;
use App\Models\Notification;
use App\Models\User;
use App\Models\WorkflowRun;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The workflow engine (v2.0.0).
 *
 * Configuration, triggers, conditional rules, automated
 * evidence checks, deadline rules, draft package preparation,
 * escalations, human approval checkpoints, execution history,
 * and failure handling with retries. Every run is recorded
 * with one row per step; workflows act only on the
 * organization's own recorded data and never send anything
 * anywhere.
 */
class WorkflowEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setupOrg(string $plan = 'starter'): array
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        if ($plan !== null) {
            $this->subscribe($organization, $plan);
        }

        return [$user, $organization];
    }

    protected function memberOf($organization, OrganizationRole $role): User
    {
        $user = User::factory()->create();
        $this->addMember($organization, $user, $role);

        return $user;
    }

    protected function actAs(User $user): void
    {
        Sanctum::actingAs($user);
    }

    protected function createWorkflow($organization, array $overrides = []): array
    {
        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/workflows', array_merge([
                'name' => 'Test Workflow '.uniqid(),
                'trigger' => 'claim.created',
                'is_active' => true,
                'conditions' => [],
                'steps' => [['type' => 'note', 'message' => 'Checked by workflow.']],
            ], $overrides));

        $response->assertCreated();

        return $response->json('data');
    }

    protected function createClaimViaApi($organization, $shipment, array $attributes = []): array
    {
        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims', array_merge([
                'shipment_id' => $shipment->getKey(),
                'requested_amount' => 250.00,
            ], $attributes));

        $response->assertCreated();

        return $response->json('data');
    }

    protected function runs($organization, array $query = []): array
    {
        return $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/workflows/runs'.(empty($query) ? '' : '?'.http_build_query($query)))
            ->assertOk()
            ->json('data');
    }

    public function test_free_plan_cannot_create_a_workflow(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/workflows', [
                'name' => 'Free Workflow',
                'trigger' => 'claim.created',
                'steps' => [['type' => 'note', 'message' => 'Hi.']],
            ])
            ->assertStatus(402);
    }

    public function test_viewer_cannot_create_a_workflow(): void
    {
        [$owner, $organization] = $this->setupOrg();
        $viewer = $this->memberOf($organization, OrganizationRole::Viewer);
        $this->actAs($viewer);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/workflows', [
                'name' => 'Viewer Workflow',
                'trigger' => 'claim.created',
                'steps' => [['type' => 'note', 'message' => 'Hi.']],
            ])
            ->assertForbidden();
    }

    public function test_create_workflow_validates_trigger_steps_and_conditions(): void
    {
        [$owner, $organization] = $this->setupOrg();

        $base = [
            'name' => 'Bad Workflow',
            'trigger' => 'claim.created',
            'steps' => [['type' => 'note', 'message' => 'Hi.']],
        ];

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/workflows', array_merge($base, ['trigger' => 'claim.exploded']))
            ->assertStatus(422);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/workflows', array_merge($base, ['steps' => [['type' => 'teleport']]]))
            ->assertStatus(422);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/workflows', array_merge($base, [
                'conditions' => [['field' => 'claim.secret', 'operator' => 'equals', 'value' => 'x']],
            ]))
            ->assertStatus(422);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/workflows', array_merge($base, [
                'steps' => [['type' => 'approval', 'role' => 'superuser']],
            ]))
            ->assertStatus(422);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/workflows', array_merge($base, [
                'steps' => [['type' => 'notify']],
            ]))
            ->assertStatus(422);
    }

    public function test_any_member_can_list_and_read_workflows(): void
    {
        [$owner, $organization] = $this->setupOrg();
        $workflow = $this->createWorkflow($organization);

        $viewer = $this->memberOf($organization, OrganizationRole::Viewer);
        $this->actAs($viewer);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/workflows')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/workflows/'.$workflow['id'])
            ->assertOk()
            ->assertJsonPath('data.name', $workflow['name']);
    }

    public function test_claim_created_trigger_runs_a_note_step(): void
    {
        [$owner, $organization] = $this->setupOrg();
        $shipment = $this->createShipment($organization);
        $this->createWorkflow($organization);

        $claim = $this->createClaimViaApi($organization, $shipment);

        $runs = $this->runs($organization);
        $this->assertCount(1, $runs);
        $this->assertSame('completed', $runs[0]['status']);
        $this->assertSame('claim.created', $runs[0]['trigger']);
        $this->assertSame($claim['id'], $runs[0]['claim_id']);
        $this->assertSame('completed', $runs[0]['steps'][0]['status']);
        $this->assertSame('note', $runs[0]['steps'][0]['step_type']);

        $this->assertTrue(ClaimReviewNote::where('organization_id', $organization->getKey())
            ->where('claim_id', $claim['id'])
            ->where('note', 'like', '[Workflow: Test Workflow%')
            ->exists());
    }

    public function test_condition_gates_the_run(): void
    {
        [$owner, $organization] = $this->setupOrg();
        $shipment = $this->createShipment($organization);
        $this->createWorkflow($organization, [
            'conditions' => [
                ['field' => 'claim.requested_amount', 'operator' => 'greater_than', 'value' => 1000],
            ],
        ]);

        $this->createClaimViaApi($organization, $shipment, ['requested_amount' => 250.00]);
        $this->assertCount(0, $this->runs($organization));

        $this->createClaimViaApi($organization, $shipment, ['requested_amount' => 1500.00]);
        $this->assertCount(1, $this->runs($organization));
    }

    public function test_evidence_check_step_records_readiness(): void
    {
        [$owner, $organization] = $this->setupOrg();
        $shipment = $this->createShipment($organization);

        EvidenceRequirement::create([
            'organization_id' => $organization->getKey(),
            'event_category_id' => null,
            'document_category' => 'bill_of_lading',
            'is_required' => true,
        ]);

        $this->createWorkflow($organization, [
            'steps' => [['type' => 'evidence_check']],
        ]);

        $claim = $this->createClaimViaApi($organization, $shipment);

        $runs = $this->runs($organization);
        $this->assertCount(1, $runs);
        $this->assertSame(0, $runs[0]['steps'][0]['result']['readiness_percentage']);
        $this->assertSame(['bill_of_lading'], $runs[0]['steps'][0]['result']['missing']);
    }

    public function test_prepare_package_step_stores_a_draft_and_sends_nothing(): void
    {
        Storage::fake('private');

        [$owner, $organization] = $this->setupOrg();
        $shipment = $this->createShipment($organization);
        $this->createWorkflow($organization, [
            'steps' => [['type' => 'prepare_package']],
        ]);

        $claim = $this->createClaimViaApi($organization, $shipment);

        $runs = $this->runs($organization);
        $this->assertCount(1, $runs);
        $this->assertTrue($runs[0]['steps'][0]['result']['draft']);

        $document = GeneratedDocument::where('organization_id', $organization->getKey())
            ->where('claim_id', $claim['id'])
            ->first();
        $this->assertNotNull($document);
        $this->assertStringStartsWith('workflow-drafts/', $document->storage_key);
        $this->assertSame('ready', $document->status->value);
        Storage::disk('private')->assertExists($document->storage_key);
    }

    public function test_notify_step_notifies_members_with_the_permission(): void
    {
        [$owner, $organization] = $this->setupOrg();
        $shipment = $this->createShipment($organization);
        $this->createWorkflow($organization, [
            'steps' => [['type' => 'notify', 'message' => 'A new claim needs a look.', 'permission' => 'view-claims']],
        ]);

        $claim = $this->createClaimViaApi($organization, $shipment);

        $this->assertTrue(Notification::where('organization_id', $organization->getKey())
            ->where('type', 'workflow.notification')
            ->where('user_id', $owner->getKey())
            ->where('body', 'A new claim needs a look.')
            ->exists());
    }

    public function test_escalate_step_writes_a_note_and_notifies_reviewers(): void
    {
        [$owner, $organization] = $this->setupOrg();
        $manager = $this->memberOf($organization, OrganizationRole::OperationsManager);
        $shipment = $this->createShipment($organization);
        $this->createWorkflow($organization, [
            'steps' => [['type' => 'escalate', 'role' => 'operations_manager', 'message' => 'High value, needs review.']],
        ]);

        $claim = $this->createClaimViaApi($organization, $shipment);

        $this->assertTrue(ClaimReviewNote::where('claim_id', $claim['id'])
            ->where('note', 'like', '%Escalated to Operations Manager%')
            ->exists());
        $this->assertTrue(Notification::where('organization_id', $organization->getKey())
            ->where('type', 'workflow.escalated')
            ->where('user_id', $manager->getKey())
            ->exists());
    }

    public function test_approval_checkpoint_pauses_until_the_named_role_approves(): void
    {
        [$owner, $organization] = $this->setupOrg();
        $manager = $this->memberOf($organization, OrganizationRole::OperationsManager);
        $dispatcher = $this->memberOf($organization, OrganizationRole::Dispatcher);
        $shipment = $this->createShipment($organization);
        $this->createWorkflow($organization, [
            'steps' => [
                ['type' => 'approval', 'role' => 'operations_manager', 'message' => 'Check the amounts before continuing.'],
                ['type' => 'note', 'message' => 'Approved and continued.'],
            ],
        ]);

        $claim = $this->createClaimViaApi($organization, $shipment);

        $runs = $this->runs($organization);
        $this->assertCount(1, $runs);
        $this->assertSame('waiting_approval', $runs[0]['status']);
        $this->assertSame('waiting_approval', $runs[0]['steps'][0]['status']);
        $this->assertSame('pending', $runs[0]['steps'][1]['status']);
        $this->assertSame('pending', $runs[0]['approvals'][0]['status']);
        $this->assertSame('operations_manager', $runs[0]['approvals'][0]['role']);

        // A dispatcher may not decide an operations-manager checkpoint.
        $this->actAs($dispatcher);
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/workflows/runs/'.$runs[0]['id'].'/approve', ['note' => 'Looks fine.'])
            ->assertStatus(422);

        // The operations manager approves; the run resumes and finishes.
        $this->actAs($manager);
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/workflows/runs/'.$runs[0]['id'].'/approve', ['note' => 'Amounts checked.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $runs = $this->runs($organization);
        $this->assertSame('completed', $runs[0]['steps'][0]['status']);
        $this->assertSame('completed', $runs[0]['steps'][1]['status']);
        $this->assertSame('approved', $runs[0]['approvals'][0]['status']);
        $this->assertSame((string) $manager->getKey(), $runs[0]['approvals'][0]['decided_by']);
    }

    public function test_rejecting_a_checkpoint_ends_the_run_and_skips_the_rest(): void
    {
        [$owner, $organization] = $this->setupOrg();
        $manager = $this->memberOf($organization, OrganizationRole::OperationsManager);
        $shipment = $this->createShipment($organization);
        $this->createWorkflow($organization, [
            'steps' => [
                ['type' => 'approval', 'role' => 'operations_manager'],
                ['type' => 'note', 'message' => 'This must not run.'],
            ],
        ]);

        $claim = $this->createClaimViaApi($organization, $shipment);
        $runs = $this->runs($organization);

        $this->actAs($manager);
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/workflows/runs/'.$runs[0]['id'].'/reject', ['note' => 'Not ready.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $runs = $this->runs($organization);
        $this->assertSame('skipped', $runs[0]['steps'][1]['status']);
        $this->assertSame('rejected', $runs[0]['approvals'][0]['status']);
        $this->assertFalse(ClaimReviewNote::where('claim_id', $claim['id'])
            ->where('note', 'like', '%This must not run.%')
            ->exists());
    }

    public function test_transition_step_moves_the_claim_through_the_transition_service(): void
    {
        [$owner, $organization] = $this->setupOrg();
        $shipment = $this->createShipment($organization);
        $this->createWorkflow($organization, [
            'steps' => [['type' => 'transition', 'to_status' => 'under_review']],
        ]);

        $claim = $this->createClaimViaApi($organization, $shipment);

        $this->assertSame('under_review', \App\Models\Claim::find($claim['id'])->status->value);
        $runs = $this->runs($organization);
        $this->assertSame('completed', $runs[0]['status']);
        $this->assertSame('under_review', $runs[0]['steps'][0]['result']['to_status']);
    }

    public function test_failed_run_can_be_retried_by_hand_from_the_failed_step(): void
    {
        [$owner, $organization] = $this->setupOrg();
        $shipment = $this->createShipment($organization);
        $this->createWorkflow($organization, [
            'steps' => [['type' => 'transition', 'to_status' => 'paid']],
        ]);

        $claim = $this->createClaimViaApi($organization, $shipment);

        $runs = $this->runs($organization);
        $this->assertCount(1, $runs);
        $this->assertSame('failed', $runs[0]['status']);
        $this->assertStringContainsString('paid', (string) $runs[0]['failure_reason']);
        $this->assertSame('failed', $runs[0]['steps'][0]['status']);
        $this->assertSame('potential', \App\Models\Claim::find($claim['id'])->status->value);

        // Manual retry re-runs the failed step and fails again
        // the same way: the definition is wrong for this claim,
        // so the failure is permanent, not transient.
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/workflows/runs/'.$runs[0]['id'].'/retry')
            ->assertOk()
            ->assertJsonPath('data.status', 'failed');

        $runs = $this->runs($organization);
        $this->assertSame(2, $runs[0]['steps'][0]['attempts']);
    }

    public function test_workflow_with_runs_cannot_be_deleted(): void
    {
        [$owner, $organization] = $this->setupOrg();
        $shipment = $this->createShipment($organization);
        $workflow = $this->createWorkflow($organization);
        $this->createClaimViaApi($organization, $shipment);

        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson('/api/v1/workflows/'.$workflow['id'])
            ->assertStatus(422);

        $empty = $this->createWorkflow($organization, ['name' => 'Empty Workflow '.uniqid()]);
        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson('/api/v1/workflows/'.$empty['id'])
            ->assertOk();
    }

    public function test_test_endpoint_dry_runs_conditions_without_starting_a_run(): void
    {
        [$owner, $organization] = $this->setupOrg();
        $shipment = $this->createShipment($organization);
        $workflow = $this->createWorkflow($organization, [
            'conditions' => [
                ['field' => 'claim.requested_amount', 'operator' => 'greater_than', 'value' => 1000],
            ],
        ]);

        $small = $this->createClaimViaApi($organization, $shipment, ['requested_amount' => 250.00]);
        $large = $this->createClaimViaApi($organization, $shipment, ['requested_amount' => 1500.00]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/workflows/'.$workflow['id'].'/test', ['claim_id' => $small['id']])
            ->assertOk()
            ->assertJsonPath('data.conditions_match', false)
            ->assertJsonPath('data.would_run', false);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/workflows/'.$workflow['id'].'/test', ['claim_id' => $large['id']])
            ->assertOk()
            ->assertJsonPath('data.conditions_match', true)
            ->assertJsonPath('data.would_run', true);

        // The dry run started no runs for the small claim, and
        // the large claim's run came from its creation, not the test.
        $this->assertCount(1, $this->runs($organization));
    }

    public function test_deadline_command_fires_approaching_workflows_once_per_day(): void
    {
        [$owner, $organization] = $this->setupOrg();
        $shipment = $this->createShipment($organization);

        $this->createWorkflow($organization, [
            'name' => 'Approaching Workflow '.uniqid(),
            'trigger' => 'deadline.approaching',
            'deadline_days_before' => 7,
            'steps' => [['type' => 'note', 'message' => 'Deadline is near.']],
        ]);
        $this->createWorkflow($organization, [
            'name' => 'Overdue Workflow '.uniqid(),
            'trigger' => 'deadline.overdue',
            'steps' => [['type' => 'note', 'message' => 'Deadline passed.']],
        ]);

        $soon = $this->createClaimViaApi($organization, $shipment, [
            'submission_deadline_at' => now()->addDays(3)->toDateTimeString(),
        ]);
        $late = $this->createClaimViaApi($organization, $shipment, [
            'submission_deadline_at' => now()->subDay()->toDateTimeString(),
        ]);

        $this->artisan('workflows:run-due', ['--organization' => (string) $organization->getKey()])
            ->assertExitCode(0);

        $runs = $this->runs($organization);
        $this->assertCount(2, $runs);
        $this->assertSame('deadline.approaching', collect($runs)->firstWhere('claim_id', $soon['id'])['trigger']);
        $this->assertSame('deadline.overdue', collect($runs)->firstWhere('claim_id', $late['id'])['trigger']);

        // A second run the same day starts nothing new.
        $this->artisan('workflows:run-due', ['--organization' => (string) $organization->getKey()])
            ->assertExitCode(0);
        $this->assertCount(2, $this->runs($organization));
    }

    public function test_evidence_triggers_fire_only_when_readiness_flips(): void
    {
        [$owner, $organization] = $this->setupOrg();
        $shipment = $this->createShipment($organization);

        EvidenceRequirement::create([
            'organization_id' => $organization->getKey(),
            'event_category_id' => null,
            'document_category' => 'bill_of_lading',
            'is_required' => true,
        ]);

        $this->createWorkflow($organization, [
            'name' => 'Evidence Done '.uniqid(),
            'trigger' => 'evidence.completed',
            'steps' => [['type' => 'note', 'message' => 'Evidence complete.']],
        ]);
        $this->createWorkflow($organization, [
            'name' => 'Evidence Missing '.uniqid(),
            'trigger' => 'evidence.incomplete',
            'steps' => [['type' => 'note', 'message' => 'Evidence went missing.']],
        ]);

        $claim = $this->createClaimViaApi($organization, $shipment);
        $this->assertCount(0, $this->runs($organization));

        $file = $this->createEvidence($organization, $shipment, ['document_category' => 'bill_of_lading']);
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims/'.$claim['id'].'/evidence', ['evidence_file_id' => $file->getKey()])
            ->assertCreated();

        $runs = $this->runs($organization);
        $this->assertCount(1, $runs);
        $this->assertSame('evidence.completed', $runs[0]['trigger']);

        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson('/api/v1/claims/'.$claim['id'].'/evidence/'.$file->getKey())
            ->assertOk();

        $runs = $this->runs($organization);
        $this->assertCount(2, $runs);
        // Runs list newest first: the detach fired the incomplete run.
        $this->assertSame('evidence.incomplete', $runs[0]['trigger']);
        $this->assertSame('evidence.completed', $runs[1]['trigger']);
    }

    public function test_runs_are_isolated_between_organizations(): void
    {
        [$owner, $organization] = $this->setupOrg();
        $shipment = $this->createShipment($organization);
        $this->createWorkflow($organization);
        $claim = $this->createClaimViaApi($organization, $shipment);
        $runs = $this->runs($organization);
        $runId = $runs[0]['id'];

        [$otherOwner, $otherOrg] = $this->setupOrg();

        $this->withHeaders($this->orgHeaders($otherOrg))
            ->getJson('/api/v1/workflows/runs')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withHeaders($this->orgHeaders($otherOrg))
            ->getJson('/api/v1/workflows/runs/'.$runId)
            ->assertNotFound();

        $this->withHeaders($this->orgHeaders($otherOrg))
            ->getJson('/api/v1/workflows/'.$runs[0]['workflow_id'])
            ->assertNotFound();
    }

    public function test_update_workflow_changes_steps_and_deactivates(): void
    {
        [$owner, $organization] = $this->setupOrg();
        $workflow = $this->createWorkflow($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/workflows/'.$workflow['id'], [
                'name' => $workflow['name'],
                'trigger' => 'claim.created',
                'is_active' => false,
                'conditions' => [],
                'steps' => [['type' => 'note', 'message' => 'Updated.']],
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }
}
