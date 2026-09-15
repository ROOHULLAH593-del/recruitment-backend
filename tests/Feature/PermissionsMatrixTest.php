<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\Interview;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A comprehensive role x endpoint permissions matrix, formalizing the manual
 * curl battery run during the project audit into permanent regression tests.
 *
 * Roles under test: guest, candidate, non-owner hr, owner hr, admin.
 */
class PermissionsMatrixTest extends TestCase
{
    use RefreshDatabase;

    // --- Job postings ---

    public function test_non_owner_hr_cannot_edit_another_hrs_job(): void
    {
        $owner = User::factory()->hr()->create();
        $otherHr = User::factory()->hr()->create();
        $job = JobPosting::factory()->create(['posted_by' => $owner->id]);

        $this->actingAs($otherHr, 'sanctum')
            ->putJson("/api/jobs/{$job->id}", ['title' => 'x'])
            ->assertStatus(403);
    }

    public function test_non_owner_hr_cannot_delete_another_hrs_job(): void
    {
        $owner = User::factory()->hr()->create();
        $otherHr = User::factory()->hr()->create();
        $job = JobPosting::factory()->create(['posted_by' => $owner->id]);

        $this->actingAs($otherHr, 'sanctum')
            ->deleteJson("/api/jobs/{$job->id}")
            ->assertStatus(403);
    }

    public function test_owner_hr_can_edit_their_own_job(): void
    {
        $owner = User::factory()->hr()->create();
        $job = JobPosting::factory()->create(['posted_by' => $owner->id]);

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/jobs/{$job->id}", ['title' => 'Updated'])
            ->assertOk();
    }

    public function test_admin_can_edit_any_job(): void
    {
        $owner = User::factory()->hr()->create();
        $admin = User::factory()->admin()->create();
        $job = JobPosting::factory()->create(['posted_by' => $owner->id]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/jobs/{$job->id}", ['title' => 'Admin Updated'])
            ->assertOk();
    }

    public function test_candidate_cannot_create_a_job(): void
    {
        $candidate = User::factory()->create();

        $this->actingAs($candidate, 'sanctum')
            ->postJson('/api/jobs', ['title' => 'x', 'description' => 'y'])
            ->assertStatus(403);
    }

    public function test_candidate_cannot_edit_a_job(): void
    {
        $candidate = User::factory()->create();
        $job = JobPosting::factory()->create();

        $this->actingAs($candidate, 'sanctum')
            ->putJson("/api/jobs/{$job->id}", ['title' => 'x'])
            ->assertStatus(403);
    }

    public function test_candidate_cannot_delete_a_job(): void
    {
        $candidate = User::factory()->create();
        $job = JobPosting::factory()->create();

        $this->actingAs($candidate, 'sanctum')
            ->deleteJson("/api/jobs/{$job->id}")
            ->assertStatus(403);
    }

    // --- Applications ---

    public function test_hr_cannot_apply_to_a_job(): void
    {
        $hr = User::factory()->hr()->create();
        $job = JobPosting::factory()->create();

        $this->actingAs($hr, 'sanctum')
            ->postJson("/api/jobs/{$job->id}/apply")
            ->assertStatus(403);
    }

    public function test_admin_cannot_apply_to_a_job(): void
    {
        $admin = User::factory()->admin()->create();
        $job = JobPosting::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/jobs/{$job->id}/apply")
            ->assertStatus(403);
    }

    public function test_candidate_cannot_update_any_application_status(): void
    {
        $candidate = User::factory()->create();
        $application = Application::factory()->create(['candidate_id' => $candidate->id]);

        $this->actingAs($candidate, 'sanctum')
            ->patchJson("/api/applications/{$application->id}/status", ['status' => 'hired'])
            ->assertStatus(403);
    }

    public function test_candidate_cannot_view_another_candidates_application(): void
    {
        $candidate = User::factory()->create();
        $other = User::factory()->create();
        $application = Application::factory()->create(['candidate_id' => $other->id]);

        $this->actingAs($candidate, 'sanctum')
            ->getJson("/api/applications/{$application->id}")
            ->assertStatus(403);
    }

    public function test_hr_can_update_any_applications_status_regardless_of_job_owner(): void
    {
        $owner = User::factory()->hr()->create();
        $otherHr = User::factory()->hr()->create();
        $job = JobPosting::factory()->create(['posted_by' => $owner->id]);
        $application = Application::factory()->status(ApplicationStatus::Applied)->create(['job_id' => $job->id]);

        $this->actingAs($otherHr, 'sanctum')
            ->patchJson("/api/applications/{$application->id}/status", ['status' => 'shortlisted'])
            ->assertOk();
    }

    // --- Candidate profile ---

    public function test_hr_cannot_access_the_candidate_profile_endpoint(): void
    {
        $hr = User::factory()->hr()->create();

        $this->actingAs($hr, 'sanctum')->getJson('/api/profile')->assertStatus(403);
    }

    public function test_admin_cannot_access_the_candidate_profile_endpoint(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')->getJson('/api/profile')->assertStatus(403);
    }

    public function test_candidate_can_access_their_own_profile(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);

        $this->actingAs($candidate, 'sanctum')->getJson('/api/profile')->assertOk();
    }

    // --- Dashboard stats ---

    public function test_candidate_cannot_access_dashboard_stats(): void
    {
        $candidate = User::factory()->create();

        $this->actingAs($candidate, 'sanctum')->getJson('/api/dashboard/stats')->assertStatus(403);
    }

    public function test_hr_can_access_dashboard_stats(): void
    {
        $hr = User::factory()->hr()->create();

        $this->actingAs($hr, 'sanctum')->getJson('/api/dashboard/stats')->assertOk();
    }

    public function test_admin_can_access_dashboard_stats(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')->getJson('/api/dashboard/stats')->assertOk();
    }

    // --- Interviews ---

    public function test_candidate_cannot_create_an_interview(): void
    {
        $candidate = User::factory()->create();
        $application = Application::factory()->status(ApplicationStatus::Shortlisted)->create(['candidate_id' => $candidate->id]);

        $this->actingAs($candidate, 'sanctum')
            ->postJson("/api/applications/{$application->id}/interview", ['scheduled_at' => now()->addWeek()->toDateTimeString()])
            ->assertStatus(403);
    }

    public function test_hr_can_update_any_interview_regardless_of_who_scheduled_it(): void
    {
        $scheduler = User::factory()->hr()->create();
        $otherHr = User::factory()->hr()->create();
        $interview = Interview::factory()->create(['interviewer_id' => $scheduler->id]);

        $this->actingAs($otherHr, 'sanctum')
            ->patchJson("/api/interviews/{$interview->id}", ['status' => 'cancelled'])
            ->assertOk();
    }

    // --- Unauthenticated (guest) access ---

    public function test_guest_cannot_list_applications(): void
    {
        $this->getJson('/api/applications')->assertStatus(401);
    }

    public function test_guest_cannot_access_profile(): void
    {
        $this->getJson('/api/profile')->assertStatus(401);
    }

    public function test_guest_cannot_access_dashboard_stats(): void
    {
        $this->getJson('/api/dashboard/stats')->assertStatus(401);
    }

    public function test_guest_cannot_list_interviews(): void
    {
        $this->getJson('/api/interviews')->assertStatus(401);
    }

    public function test_guest_cannot_create_a_job(): void
    {
        $this->postJson('/api/jobs', ['title' => 'x', 'description' => 'y'])->assertStatus(401);
    }

    public function test_guest_cannot_apply_to_a_job(): void
    {
        $job = JobPosting::factory()->create();

        $this->postJson("/api/jobs/{$job->id}/apply")->assertStatus(401);
    }

    public function test_guest_can_browse_open_jobs(): void
    {
        JobPosting::factory()->create();

        $this->getJson('/api/jobs')->assertOk();
    }

    public function test_guest_can_view_a_single_open_job(): void
    {
        $job = JobPosting::factory()->create();

        $this->getJson("/api/jobs/{$job->id}")->assertOk();
    }
}
