<?php

namespace Tests\Feature;

use App\Mail\OrganizationInvitationMail;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_owner_can_invite_and_the_email_is_sent(): void
    {
        Mail::fake();

        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/invitations', [
                'email' => 'newhire@example.com',
                'role' => 'dispatcher',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'newhire@example.com')
            ->assertJsonPath('data.role', 'dispatcher')
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('organization_invitations', [
            'organization_id' => $organization->id,
            'email' => 'newhire@example.com',
            'role' => 'dispatcher',
        ]);

        Mail::assertQueued(OrganizationInvitationMail::class, function ($mail) {
            return $mail->hasTo('newhire@example.com');
        });
    }

    public function test_inviting_an_existing_member_fails(): void
    {
        Mail::fake();

        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');
        $member = User::factory()->create();
        $this->addMember($organization, $member, OrganizationRole::Viewer);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/invitations', [
                'email' => $member->email,
                'role' => 'viewer',
            ])->assertUnprocessable();

        Mail::assertNothingQueued();
    }

    public function test_a_second_pending_invitation_for_the_same_email_fails(): void
    {
        Mail::fake();

        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/invitations', [
                'email' => 'newhire@example.com',
                'role' => 'dispatcher',
            ])->assertCreated();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/invitations', [
                'email' => 'newhire@example.com',
                'role' => 'viewer',
            ])->assertUnprocessable();
    }

    public function test_an_admin_cannot_invite_an_owner(): void
    {
        [$admin, $organization] = $this->actingAsMemberOf(OrganizationRole::Admin);
        $this->subscribe($organization, 'starter');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/invitations', [
                'email' => 'newhire@example.com',
                'role' => 'owner',
            ])->assertForbidden();
    }

    public function test_an_invited_user_can_accept_and_becomes_a_member(): void
    {
        Mail::fake();

        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/invitations', [
                'email' => 'newhire@example.com',
                'role' => 'billing_staff',
            ])->assertCreated();

        // The raw token only exists in the email. Recover it from the
        // queued mail, the same way the invitee would read it.
        $rawToken = null;
        Mail::assertQueued(OrganizationInvitationMail::class, function ($mail) use (&$rawToken) {
            $rawToken = $mail->rawToken;

            return true;
        });
        $this->assertNotNull($rawToken);

        $invitee = User::factory()->create(['email' => 'newhire@example.com']);
        Sanctum::actingAs($invitee);

        $this->postJson('/api/v1/invitations/accept', ['token' => $rawToken])
            ->assertOk()
            ->assertJsonPath('organization_id', (string) $organization->getKey());

        $this->assertDatabaseHas('organization_members', [
            'organization_id' => $organization->id,
            'user_id' => $invitee->id,
            'role' => 'billing_staff',
            'status' => 'active',
        ]);

        $this->assertNotNull(
            OrganizationInvitation::withoutGlobalScope('organization')->first()->accepted_at
        );
    }

    public function test_accepting_with_an_unknown_token_fails(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/invitations/accept', ['token' => Str::random(48)])
            ->assertNotFound();
    }

    public function test_accepting_an_expired_invitation_fails(): void
    {
        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $rawToken = Str::random(48);
        $organization->invitations()->create([
            'email' => 'newhire@example.com',
            'role' => OrganizationRole::Viewer,
            'token' => hash('sha256', $rawToken),
            'expires_at' => now()->subDay(),
            'invited_by' => $owner->id,
        ]);

        $invitee = User::factory()->create(['email' => 'newhire@example.com']);
        Sanctum::actingAs($invitee);

        $this->postJson('/api/v1/invitations/accept', ['token' => $rawToken])
            ->assertNotFound();
    }

    public function test_an_invitation_cannot_be_accepted_by_a_different_email(): void
    {
        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $rawToken = Str::random(48);
        $organization->invitations()->create([
            'email' => 'newhire@example.com',
            'role' => OrganizationRole::Viewer,
            'token' => hash('sha256', $rawToken),
            'expires_at' => now()->addDays(7),
            'invited_by' => $owner->id,
        ]);

        $someoneElse = User::factory()->create(['email' => 'someone-else@example.com']);
        Sanctum::actingAs($someoneElse);

        $this->postJson('/api/v1/invitations/accept', ['token' => $rawToken])
            ->assertForbidden();
    }

    public function test_an_invitation_can_be_revoked(): void
    {
        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $invitation = $organization->invitations()->create([
            'email' => 'newhire@example.com',
            'role' => OrganizationRole::Viewer,
            'token' => hash('sha256', Str::random(48)),
            'expires_at' => now()->addDays(7),
            'invited_by' => $owner->id,
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson('/api/v1/invitations/'.$invitation->getKey())
            ->assertOk();

        $this->assertDatabaseMissing('organization_invitations', ['id' => $invitation->getKey()]);
    }
}
