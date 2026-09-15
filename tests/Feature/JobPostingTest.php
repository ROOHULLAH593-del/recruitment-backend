<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\Application;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobPostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_sees_only_open_jobs(): void
    {
        JobPosting::factory()->create(['title' => 'Open Role']);
        JobPosting::factory()->draft()->create(['title' => 'Draft Role']);
        JobPosting::factory()->closed()->create(['title' => 'Closed Role']);

        $response = $this->getJson('/api/jobs');

        $response->assertOk();
        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Open Role'));
        $this->assertFalse($titles->contains('Draft Role'));
        $this->assertFalse($titles->contains('Closed Role'));
    }

    public function test_candidate_sees_only_open_jobs(): void
    {
        $candidate = User::factory()->create();
        JobPosting::factory()->create(['title' => 'Open Role']);
        JobPosting::factory()->draft()->create(['title' => 'Draft Role']);

        $response = $this->actingAs($candidate, 'sanctum')->getJson('/api/jobs');

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Open Role'));
        $this->assertFalse($titles->contains('Draft Role'));
    }

    public function test_hr_sees_jobs_of_every_status(): void
    {
        $hr = User::factory()->hr()->create();
        JobPosting::factory()->create(['title' => 'Open Role']);
        JobPosting::factory()->draft()->create(['title' => 'Draft Role']);
        JobPosting::factory()->closed()->create(['title' => 'Closed Role']);

        $response = $this->actingAs($hr, 'sanctum')->getJson('/api/jobs');

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Open Role'));
        $this->assertTrue($titles->contains('Draft Role'));
        $this->assertTrue($titles->contains('Closed Role'));
    }

    public function test_guest_gets_404_for_a_draft_job(): void
    {
        $job = JobPosting::factory()->draft()->create();

        $this->getJson("/api/jobs/{$job->id}")->assertStatus(404);
    }

    public function test_guest_gets_404_for_a_closed_job(): void
    {
        $job = JobPosting::factory()->closed()->create();

        $this->getJson("/api/jobs/{$job->id}")->assertStatus(404);
    }

    public function test_hr_can_view_a_draft_job(): void
    {
        $hr = User::factory()->hr()->create();
        $job = JobPosting::factory()->draft()->create();

        $this->actingAs($hr, 'sanctum')->getJson("/api/jobs/{$job->id}")->assertOk();
    }

    public function test_candidate_can_still_view_a_closed_job_they_applied_to(): void
    {
        $candidate = User::factory()->create();
        $job = JobPosting::factory()->create();
        Application::factory()->for($job, 'job')->create(['candidate_id' => $candidate->id]);

        $job->update(['status' => JobStatus::Closed]);

        $this->actingAs($candidate, 'sanctum')
            ->getJson("/api/jobs/{$job->id}")
            ->assertOk();
    }

    public function test_candidate_gets_404_for_a_closed_job_they_did_not_apply_to(): void
    {
        $candidate = User::factory()->create();
        $job = JobPosting::factory()->closed()->create();

        $this->actingAs($candidate, 'sanctum')
            ->getJson("/api/jobs/{$job->id}")
            ->assertStatus(404);
    }

    public function test_hr_can_create_a_job_posting_and_it_defaults_to_draft(): void
    {
        $hr = User::factory()->hr()->create();

        $response = $this->actingAs($hr, 'sanctum')->postJson('/api/jobs', [
            'title' => 'Backend Engineer',
            'description' => 'Build things.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Backend Engineer')
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('job_postings', [
            'title' => 'Backend Engineer',
            'posted_by' => $hr->id,
        ]);
    }

    public function test_admin_can_create_a_job_posting(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')->postJson('/api/jobs', [
            'title' => 'Ops Manager',
            'description' => 'Run things.',
        ])->assertCreated();
    }

    public function test_candidate_cannot_create_a_job_posting(): void
    {
        $candidate = User::factory()->create();

        $this->actingAs($candidate, 'sanctum')->postJson('/api/jobs', [
            'title' => 'Backend Engineer',
            'description' => 'Build things.',
        ])->assertStatus(403);
    }

    public function test_job_creation_requires_title_and_description(): void
    {
        $hr = User::factory()->hr()->create();

        $this->actingAs($hr, 'sanctum')->postJson('/api/jobs', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'description']);
    }

    public function test_owner_hr_can_update_their_own_job(): void
    {
        $hr = User::factory()->hr()->create();
        $job = JobPosting::factory()->create(['posted_by' => $hr->id, 'title' => 'Old Title']);

        $this->actingAs($hr, 'sanctum')
            ->putJson("/api/jobs/{$job->id}", ['title' => 'New Title'])
            ->assertOk()
            ->assertJsonPath('data.title', 'New Title');
    }

    public function test_non_owner_hr_cannot_update_someone_elses_job(): void
    {
        $owner = User::factory()->hr()->create();
        $otherHr = User::factory()->hr()->create();
        $job = JobPosting::factory()->create(['posted_by' => $owner->id]);

        $this->actingAs($otherHr, 'sanctum')
            ->putJson("/api/jobs/{$job->id}", ['title' => 'Hijacked'])
            ->assertStatus(403);
    }

    public function test_admin_can_update_any_job(): void
    {
        $owner = User::factory()->hr()->create();
        $admin = User::factory()->admin()->create();
        $job = JobPosting::factory()->create(['posted_by' => $owner->id]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/jobs/{$job->id}", ['title' => 'Admin Edited'])
            ->assertOk();
    }

    public function test_candidate_cannot_update_a_job(): void
    {
        $candidate = User::factory()->create();
        $job = JobPosting::factory()->create();

        $this->actingAs($candidate, 'sanctum')
            ->putJson("/api/jobs/{$job->id}", ['title' => 'Nope'])
            ->assertStatus(403);
    }

    public function test_owner_hr_can_delete_their_own_job_with_no_applications(): void
    {
        $hr = User::factory()->hr()->create();
        $job = JobPosting::factory()->create(['posted_by' => $hr->id]);

        $this->actingAs($hr, 'sanctum')
            ->deleteJson("/api/jobs/{$job->id}")
            ->assertOk();

        $this->assertDatabaseMissing('job_postings', ['id' => $job->id]);
    }

    public function test_non_owner_hr_cannot_delete_someone_elses_job(): void
    {
        $owner = User::factory()->hr()->create();
        $otherHr = User::factory()->hr()->create();
        $job = JobPosting::factory()->create(['posted_by' => $owner->id]);

        $this->actingAs($otherHr, 'sanctum')
            ->deleteJson("/api/jobs/{$job->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('job_postings', ['id' => $job->id]);
    }

    public function test_deleting_a_job_with_existing_applications_is_blocked(): void
    {
        $hr = User::factory()->hr()->create();
        $job = JobPosting::factory()->create(['posted_by' => $hr->id]);
        Application::factory()->for($job, 'job')->create();

        $response = $this->actingAs($hr, 'sanctum')->deleteJson("/api/jobs/{$job->id}");

        $response->assertStatus(422)->assertJsonValidationErrors('job');
        $this->assertDatabaseHas('job_postings', ['id' => $job->id]);
    }

    public function test_deleting_a_job_with_no_applications_succeeds_for_the_owner(): void
    {
        $hr = User::factory()->hr()->create();
        $job = JobPosting::factory()->create(['posted_by' => $hr->id]);

        $this->actingAs($hr, 'sanctum')
            ->deleteJson("/api/jobs/{$job->id}")
            ->assertOk();
    }
}
