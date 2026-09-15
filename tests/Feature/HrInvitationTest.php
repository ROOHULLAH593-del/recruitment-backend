<?php

namespace Tests\Feature;

use App\Enums\InvitationStatus;
use App\Enums\UserRole;
use App\Models\Application;
use App\Models\HrInvitation;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HrInvitationTest extends TestCase
{
    use RefreshDatabase;

    // --- Creation (admin-only) ---

    public function test_admin_can_create_an_invitation(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/invitations', ['role_offered' => 'hr'])
            ->assertCreated();

        $response->assertJsonPath('data.role_offered', 'hr');
        $response->assertJsonPath('data.status', InvitationStatus::PendingUse->value);
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame(64, strlen($response->json('data.token')));

        $this->assertDatabaseHas('hr_invitations', [
            'role_offered' => 'hr',
            'invited_by' => $admin->id,
            'status' => InvitationStatus::PendingUse->value,
        ]);

        $invitation = HrInvitation::first();
        $this->assertTrue($invitation->expires_at->isBetween(now()->addDays(6), now()->addDays(8)));
    }

    public function test_admin_can_create_an_assistant_hr_invitation(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/invitations', ['role_offered' => 'assistant_hr'])
            ->assertCreated()
            ->assertJsonPath('data.role_offered', 'assistant_hr');
    }

    public function test_cannot_create_an_invitation_for_a_disallowed_role(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/invitations', ['role_offered' => 'admin'])
            ->assertStatus(422);
    }

    public function test_hr_cannot_create_an_invitation(): void
    {
        $hr = User::factory()->hr()->create();

        $this->actingAs($hr, 'sanctum')
            ->postJson('/api/admin/invitations', ['role_offered' => 'hr'])
            ->assertStatus(403);
    }

    public function test_assistant_hr_cannot_create_an_invitation(): void
    {
        $assistantHr = User::factory()->assistantHr()->create();

        $this->actingAs($assistantHr, 'sanctum')
            ->postJson('/api/admin/invitations', ['role_offered' => 'hr'])
            ->assertStatus(403);
    }

    public function test_candidate_cannot_create_an_invitation(): void
    {
        $candidate = User::factory()->create();

        $this->actingAs($candidate, 'sanctum')
            ->postJson('/api/admin/invitations', ['role_offered' => 'hr'])
            ->assertStatus(403);
    }

    public function test_guest_cannot_create_an_invitation(): void
    {
        $this->postJson('/api/admin/invitations', ['role_offered' => 'hr'])->assertStatus(401);
    }

    // --- Listing (admin-only) ---

    public function test_admin_can_list_invitations_across_every_status(): void
    {
        $admin = User::factory()->admin()->create();
        HrInvitation::factory()->create();
        HrInvitation::factory()->pendingReview()->create();
        HrInvitation::factory()->approved()->create();
        HrInvitation::factory()->rejected()->create();
        HrInvitation::factory()->expired()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/invitations')
            ->assertOk();

        $this->assertCount(5, $response->json('data'));
    }

    public function test_hr_cannot_list_invitations(): void
    {
        $hr = User::factory()->hr()->create();

        $this->actingAs($hr, 'sanctum')->getJson('/api/admin/invitations')->assertStatus(403);
    }

    public function test_guest_cannot_list_invitations(): void
    {
        $this->getJson('/api/admin/invitations')->assertStatus(401);
    }

    // --- Public token lookup ---

    public function test_guest_can_look_up_a_pending_invitation_by_token(): void
    {
        $invitation = HrInvitation::factory()->create(['role_offered' => UserRole::Hr]);

        $this->getJson("/api/invitations/{$invitation->token}")
            ->assertOk()
            ->assertJsonPath('data.role_offered', 'hr')
            ->assertJsonMissingPath('data.applicant_email')
            ->assertJsonMissingPath('data.invited_by');
    }

    public function test_unknown_token_returns_not_found(): void
    {
        $this->getJson('/api/invitations/not-a-real-token')->assertStatus(404);
    }

    public function test_expired_token_is_auto_marked_expired_and_returns_not_found(): void
    {
        $invitation = HrInvitation::factory()->pastDueButNotYetExpired()->create();

        $this->getJson("/api/invitations/{$invitation->token}")->assertStatus(404);

        $this->assertSame(InvitationStatus::Expired, $invitation->fresh()->status);
    }

    public function test_already_reviewed_invitation_is_not_found_by_token_lookup(): void
    {
        $invitation = HrInvitation::factory()->approved()->create();

        $this->getJson("/api/invitations/{$invitation->token}")->assertStatus(404);
    }

    // --- Applying ---

    public function test_valid_apply_moves_invitation_to_pending_review_with_hashed_password(): void
    {
        $invitation = HrInvitation::factory()->create();

        $this->postJson("/api/invitations/{$invitation->token}/apply", [
            'name' => 'Jordan Applicant',
            'email' => 'jordan@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();

        $invitation->refresh();
        $this->assertSame(InvitationStatus::PendingReview, $invitation->status);
        $this->assertSame('Jordan Applicant', $invitation->applicant_name);
        $this->assertSame('jordan@example.com', $invitation->applicant_email);
        $this->assertNotSame('Password123', $invitation->applicant_password);
        $this->assertTrue(Hash::check('Password123', $invitation->applicant_password));
        $this->assertNotNull($invitation->submitted_at);
    }

    public function test_apply_rejects_an_expired_token(): void
    {
        $invitation = HrInvitation::factory()->pastDueButNotYetExpired()->create();

        $this->postJson("/api/invitations/{$invitation->token}/apply", [
            'name' => 'Jordan Applicant',
            'email' => 'jordan@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertStatus(422);

        $this->assertSame(InvitationStatus::Expired, $invitation->fresh()->status);
    }

    public function test_apply_rejects_an_already_used_token(): void
    {
        $invitation = HrInvitation::factory()->pendingReview()->create();

        $this->postJson("/api/invitations/{$invitation->token}/apply", [
            'name' => 'Second Attempt',
            'email' => 'second@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertStatus(422);
    }

    public function test_apply_rejects_an_unknown_token(): void
    {
        $this->postJson('/api/invitations/not-a-real-token/apply', [
            'name' => 'Jordan Applicant',
            'email' => 'jordan@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertStatus(404);
    }

    public function test_apply_validates_required_fields(): void
    {
        $invitation = HrInvitation::factory()->create();

        $this->postJson("/api/invitations/{$invitation->token}/apply", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_apply_rejects_an_email_already_registered_to_a_user(): void
    {
        $existing = User::factory()->create(['email' => 'taken@example.com']);
        $invitation = HrInvitation::factory()->create();

        $this->postJson("/api/invitations/{$invitation->token}/apply", [
            'name' => 'Jordan Applicant',
            'email' => $existing->email,
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    // --- Accept ---

    public function test_admin_can_accept_a_pending_review_invitation_creating_a_real_account(): void
    {
        $admin = User::factory()->admin()->create();
        $invitation = HrInvitation::factory()->pendingReview()->create(['role_offered' => UserRole::AssistantHr]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/invitations/{$invitation->id}/accept")
            ->assertOk();

        $response->assertJsonPath('user.email', $invitation->applicant_email);
        $response->assertJsonPath('user.role', 'assistant_hr');
        $response->assertJsonPath('invitation.status', InvitationStatus::Approved->value);

        $user = User::where('email', $invitation->applicant_email)->first();
        $this->assertNotNull($user);
        $this->assertSame(UserRole::AssistantHr, $user->role);
        $this->assertSame($invitation->applicant_name, $user->name);
        $this->assertTrue(Hash::check('password', $user->password));

        $invitation->refresh();
        $this->assertSame(InvitationStatus::Approved, $invitation->status);
        $this->assertSame($admin->id, $invitation->reviewed_by);
        $this->assertNotNull($invitation->reviewed_at);
    }

    public function test_accepting_creates_a_user_with_the_hr_role_when_that_was_offered(): void
    {
        $admin = User::factory()->admin()->create();
        $invitation = HrInvitation::factory()->pendingReview()->create(['role_offered' => UserRole::Hr]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/invitations/{$invitation->id}/accept")
            ->assertOk();

        $user = User::where('email', $invitation->applicant_email)->first();
        $this->assertSame(UserRole::Hr, $user->role);
    }

    public function test_cannot_accept_an_invitation_that_is_still_pending_use(): void
    {
        $admin = User::factory()->admin()->create();
        $invitation = HrInvitation::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/invitations/{$invitation->id}/accept")
            ->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => $invitation->applicant_email]);
    }

    public function test_cannot_accept_an_already_approved_invitation(): void
    {
        $admin = User::factory()->admin()->create();
        $invitation = HrInvitation::factory()->approved()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/invitations/{$invitation->id}/accept")
            ->assertStatus(422);
    }

    public function test_hr_cannot_accept_an_invitation(): void
    {
        $hr = User::factory()->hr()->create();
        $invitation = HrInvitation::factory()->pendingReview()->create();

        $this->actingAs($hr, 'sanctum')
            ->postJson("/api/admin/invitations/{$invitation->id}/accept")
            ->assertStatus(403);
    }

    public function test_guest_cannot_accept_an_invitation(): void
    {
        $invitation = HrInvitation::factory()->pendingReview()->create();

        $this->postJson("/api/admin/invitations/{$invitation->id}/accept")->assertStatus(401);
    }

    // --- Reject ---

    public function test_admin_can_reject_a_pending_review_invitation_creating_no_account(): void
    {
        $admin = User::factory()->admin()->create();
        $invitation = HrInvitation::factory()->pendingReview()->create();
        $usersBefore = User::count();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/invitations/{$invitation->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', InvitationStatus::Rejected->value);

        $this->assertSame($usersBefore, User::count());
        $this->assertDatabaseMissing('users', ['email' => $invitation->applicant_email]);

        $invitation->refresh();
        $this->assertSame(InvitationStatus::Rejected, $invitation->status);
        $this->assertSame($admin->id, $invitation->reviewed_by);
    }

    public function test_hr_cannot_reject_an_invitation(): void
    {
        $hr = User::factory()->hr()->create();
        $invitation = HrInvitation::factory()->pendingReview()->create();

        $this->actingAs($hr, 'sanctum')
            ->postJson("/api/admin/invitations/{$invitation->id}/reject")
            ->assertStatus(403);
    }

    // --- assistant_hr job-posting restriction (view-only) ---

    public function test_assistant_hr_can_view_job_postings(): void
    {
        $assistantHr = User::factory()->assistantHr()->create();
        $job = JobPosting::factory()->create();

        $this->actingAs($assistantHr, 'sanctum')
            ->getJson("/api/jobs/{$job->id}")
            ->assertOk();
    }

    public function test_assistant_hr_can_see_draft_jobs_in_the_listing(): void
    {
        $assistantHr = User::factory()->assistantHr()->create();
        JobPosting::factory()->draft()->create();

        $response = $this->actingAs($assistantHr, 'sanctum')->getJson('/api/jobs')->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_assistant_hr_cannot_create_a_job_posting(): void
    {
        $assistantHr = User::factory()->assistantHr()->create();

        $this->actingAs($assistantHr, 'sanctum')
            ->postJson('/api/jobs', ['title' => 'x', 'description' => 'y'])
            ->assertStatus(403);
    }

    public function test_assistant_hr_cannot_update_a_job_posting(): void
    {
        $assistantHr = User::factory()->assistantHr()->create();
        $job = JobPosting::factory()->create();

        $this->actingAs($assistantHr, 'sanctum')
            ->putJson("/api/jobs/{$job->id}", ['title' => 'Updated'])
            ->assertStatus(403);
    }

    public function test_assistant_hr_cannot_delete_a_job_posting(): void
    {
        $assistantHr = User::factory()->assistantHr()->create();
        $job = JobPosting::factory()->create();

        $this->actingAs($assistantHr, 'sanctum')
            ->deleteJson("/api/jobs/{$job->id}")
            ->assertStatus(403);
    }

    // --- assistant_hr full access to applications/interviews/dashboard ---

    public function test_assistant_hr_can_list_applications(): void
    {
        $assistantHr = User::factory()->assistantHr()->create();

        $this->actingAs($assistantHr, 'sanctum')->getJson('/api/applications')->assertOk();
    }

    public function test_assistant_hr_can_update_an_applications_status(): void
    {
        $assistantHr = User::factory()->assistantHr()->create();
        $application = Application::factory()->create();

        $this->actingAs($assistantHr, 'sanctum')
            ->patchJson("/api/applications/{$application->id}/status", ['status' => 'shortlisted'])
            ->assertOk();
    }

    public function test_assistant_hr_can_list_interviews(): void
    {
        $assistantHr = User::factory()->assistantHr()->create();

        $this->actingAs($assistantHr, 'sanctum')->getJson('/api/interviews')->assertOk();
    }

    public function test_assistant_hr_can_access_dashboard_stats(): void
    {
        $assistantHr = User::factory()->assistantHr()->create();

        $this->actingAs($assistantHr, 'sanctum')->getJson('/api/dashboard/stats')->assertOk();
    }
}
