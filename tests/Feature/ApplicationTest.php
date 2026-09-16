<?php

namespace Tests\Feature;

use App\Enums\EducationLevel;
use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_candidate_can_apply_to_an_open_job(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        $job = JobPosting::factory()->create();

        $response = $this->actingAs($candidate, 'sanctum')->postJson("/api/jobs/{$job->id}/apply");

        $response->assertCreated()
            ->assertJsonPath('data.status', 'applied')
            ->assertJsonPath('data.job.id', $job->id);

        $this->assertDatabaseHas('applications', [
            'job_id' => $job->id,
            'candidate_id' => $candidate->id,
            'status' => 'applied',
        ]);
    }

    public function test_applying_twice_to_the_same_job_is_rejected(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        $job = JobPosting::factory()->create();

        $this->actingAs($candidate, 'sanctum')->postJson("/api/jobs/{$job->id}/apply")->assertCreated();

        $response = $this->actingAs($candidate, 'sanctum')->postJson("/api/jobs/{$job->id}/apply");

        $response->assertStatus(409);
        $this->assertSame(1, Application::where('job_id', $job->id)->where('candidate_id', $candidate->id)->count());
    }

    public function test_applying_to_a_draft_job_is_rejected(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        $job = JobPosting::factory()->draft()->create();

        $this->actingAs($candidate, 'sanctum')
            ->postJson("/api/jobs/{$job->id}/apply")
            ->assertStatus(422)
            ->assertJsonValidationErrors('job');
    }

    public function test_applying_to_a_closed_job_is_rejected(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        $job = JobPosting::factory()->closed()->create();

        $this->actingAs($candidate, 'sanctum')
            ->postJson("/api/jobs/{$job->id}/apply")
            ->assertStatus(422)
            ->assertJsonValidationErrors('job');
    }

    public function test_hr_cannot_apply_to_a_job(): void
    {
        $hr = User::factory()->hr()->create();
        $job = JobPosting::factory()->create();

        $this->actingAs($hr, 'sanctum')
            ->postJson("/api/jobs/{$job->id}/apply")
            ->assertStatus(403);
    }

    public function test_match_score_is_calculated_on_application_creation(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create([
            'user_id' => $candidate->id,
            'skills' => ['PHP', 'React'],
            'years_experience' => 5,
            'education_level' => EducationLevel::Masters,
        ]);
        $job = JobPosting::factory()->create([
            'required_skills' => ['PHP', 'React'],
            'min_experience' => 2,
            'education_requirement' => EducationLevel::Bachelors,
        ]);

        $this->actingAs($candidate, 'sanctum')->postJson("/api/jobs/{$job->id}/apply")->assertCreated();

        $application = Application::where('candidate_id', $candidate->id)->where('job_id', $job->id)->first();
        $this->assertSame('100.00', $application->match_score);
    }

    public function test_match_score_reflects_partial_match_on_creation(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create([
            'user_id' => $candidate->id,
            'skills' => ['PHP'],
            'years_experience' => 0,
            'education_level' => null,
        ]);
        $job = JobPosting::factory()->create([
            'required_skills' => ['PHP', 'React'],
            'min_experience' => 4,
            'education_requirement' => EducationLevel::Bachelors,
        ]);

        $this->actingAs($candidate, 'sanctum')->postJson("/api/jobs/{$job->id}/apply")->assertCreated();

        $application = Application::where('candidate_id', $candidate->id)->where('job_id', $job->id)->first();

        // skills: 1/2 = 50% * 0.5 = 25; experience: 0/4 = 0; education: no profile level -> 0
        $this->assertSame('25.00', $application->match_score);
    }

    public function test_candidate_can_view_their_own_application(): void
    {
        $candidate = User::factory()->create();
        $application = Application::factory()->create(['candidate_id' => $candidate->id]);

        $this->actingAs($candidate, 'sanctum')
            ->getJson("/api/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $application->id);
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

    public function test_hr_can_view_any_application(): void
    {
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->create();

        $this->actingAs($hr, 'sanctum')
            ->getJson("/api/applications/{$application->id}")
            ->assertOk();
    }

    public function test_hr_viewing_an_application_sees_the_candidates_full_profile(): void
    {
        $hr = User::factory()->hr()->create();
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create([
            'user_id' => $candidate->id,
            'skills' => ['PHP', 'Laravel'],
            'education_level' => EducationLevel::Masters,
            'years_experience' => 6,
            'resume_text' => 'A seasoned backend engineer.',
        ]);
        $application = Application::factory()->create(['candidate_id' => $candidate->id]);

        $this->actingAs($hr, 'sanctum')
            ->getJson("/api/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.candidate.candidate_profile.skills', ['PHP', 'Laravel'])
            ->assertJsonPath('data.candidate.candidate_profile.education_level', 'masters')
            ->assertJsonPath('data.candidate.candidate_profile.years_experience', 6)
            ->assertJsonPath('data.candidate.candidate_profile.resume_text', 'A seasoned backend engineer.');
    }

    public function test_candidate_index_includes_the_candidates_profile_for_hr(): void
    {
        $hr = User::factory()->hr()->create();
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id, 'skills' => ['Sales']]);
        Application::factory()->create(['candidate_id' => $candidate->id]);

        $this->actingAs($hr, 'sanctum')
            ->getJson('/api/applications')
            ->assertOk()
            ->assertJsonPath('data.0.candidate.candidate_profile.skills', ['Sales']);
    }

    public function test_a_candidate_without_access_never_receives_another_candidates_profile_data(): void
    {
        $candidate = User::factory()->create();
        $other = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $other->id, 'skills' => ['Secret Skill']]);
        $application = Application::factory()->create(['candidate_id' => $other->id]);

        $response = $this->actingAs($candidate, 'sanctum')->getJson("/api/applications/{$application->id}");

        $response->assertStatus(403);
        $this->assertStringNotContainsString('Secret Skill', $response->getContent());
    }

    public function test_candidate_index_only_returns_their_own_applications(): void
    {
        $candidate = User::factory()->create();
        Application::factory()->count(2)->create(['candidate_id' => $candidate->id]);
        Application::factory()->count(3)->create();

        $response = $this->actingAs($candidate, 'sanctum')->getJson('/api/applications');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_hr_index_returns_all_applications(): void
    {
        $hr = User::factory()->hr()->create();
        Application::factory()->count(3)->create();

        $response = $this->actingAs($hr, 'sanctum')->getJson('/api/applications');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_pagination_does_not_duplicate_rows_when_many_share_the_same_applied_at(): void
    {
        // Regression test: sorting by applied_at alone is not a stable sort when many
        // rows share the exact same timestamp (as production-scale data can), which let
        // rows appear on more than one page or vanish depending on query plan. The fix
        // adds `id` as a secondary tiebreaker.
        $hr = User::factory()->hr()->create();
        $tiedTimestamp = now()->subDay();

        Application::factory()->count(30)->create(['applied_at' => $tiedTimestamp]);

        $pageOne = $this->actingAs($hr, 'sanctum')
            ->getJson('/api/applications?page=1&per_page=15')
            ->json('data');
        $pageTwo = $this->actingAs($hr, 'sanctum')
            ->getJson('/api/applications?page=2&per_page=15')
            ->json('data');

        $idsOne = collect($pageOne)->pluck('id');
        $idsTwo = collect($pageTwo)->pluck('id');

        $this->assertCount(15, $idsOne);
        $this->assertCount(15, $idsTwo);
        $this->assertEmpty($idsOne->intersect($idsTwo), 'Pages must not overlap even when sort values tie.');
        $this->assertCount(30, $idsOne->merge($idsTwo)->unique());
    }
}
