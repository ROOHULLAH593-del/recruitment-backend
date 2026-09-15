<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MatchScoreRecalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_a_candidate_profile_recalculates_match_score_for_all_their_applications(): void
    {
        $candidate = User::factory()->create();
        $profile = CandidateProfile::factory()->create([
            'user_id' => $candidate->id,
            'skills' => [],
            'years_experience' => 0,
            'education_level' => null,
        ]);

        $jobOne = JobPosting::factory()->create(['required_skills' => ['PHP'], 'min_experience' => 0, 'education_requirement' => null]);
        $jobTwo = JobPosting::factory()->create(['required_skills' => ['React'], 'min_experience' => 0, 'education_requirement' => null]);

        $applicationOne = Application::factory()->create(['candidate_id' => $candidate->id, 'job_id' => $jobOne->id]);
        $applicationTwo = Application::factory()->create(['candidate_id' => $candidate->id, 'job_id' => $jobTwo->id]);

        // No skill overlap yet, but min_experience 0 and no education requirement both
        // give full credit: skills 0*.5 + experience 100*.3 + education 100*.2 = 50.
        $this->assertSame('50.00', $applicationOne->fresh()->match_score);
        $this->assertSame('50.00', $applicationTwo->fresh()->match_score);

        $profile->update(['skills' => ['PHP', 'React']]);

        $this->assertSame('100.00', $applicationOne->fresh()->match_score);
        $this->assertSame('100.00', $applicationTwo->fresh()->match_score);
    }

    public function test_updating_a_candidate_profile_does_not_use_n_plus_one_queries_for_jobs(): void
    {
        $candidate = User::factory()->create();
        $profile = CandidateProfile::factory()->create(['user_id' => $candidate->id]);

        Application::factory()->count(5)->create(['candidate_id' => $candidate->id]);

        DB::enableQueryLog();
        $profile->update(['years_experience' => 10]);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 1 update to the profile, 1 select for applications, 1 select for jobs (whereIn),
        // plus up to 5 application updates (one per row whose score actually changed).
        $this->assertLessThanOrEqual(8, $queryCount, 'Expected a bounded number of queries, not one per application per job.');
    }

    public function test_updating_a_job_postings_requirements_recalculates_match_score_for_its_applications(): void
    {
        $hr = User::factory()->hr()->create();
        $job = JobPosting::factory()->create([
            'posted_by' => $hr->id,
            'required_skills' => ['PHP', 'React', 'AWS', 'Docker'],
            'min_experience' => 5,
            'education_requirement' => null,
        ]);

        $candidate = User::factory()->create();
        CandidateProfile::factory()->create([
            'user_id' => $candidate->id,
            'skills' => ['PHP', 'React'],
            'years_experience' => 1,
            'education_level' => null,
        ]);

        $application = Application::factory()->create(['candidate_id' => $candidate->id, 'job_id' => $job->id]);
        $scoreBefore = $application->fresh()->match_score;

        $this->actingAs($hr, 'sanctum')->putJson("/api/jobs/{$job->id}", [
            'required_skills' => ['PHP', 'React'],
            'min_experience' => 0,
        ])->assertOk();

        $scoreAfter = $application->fresh()->match_score;

        $this->assertNotSame($scoreBefore, $scoreAfter);
        $this->assertSame('100.00', $scoreAfter);
    }

    public function test_updating_a_job_with_no_applications_does_not_error(): void
    {
        $hr = User::factory()->hr()->create();
        $job = JobPosting::factory()->create(['posted_by' => $hr->id]);

        $this->actingAs($hr, 'sanctum')
            ->putJson("/api/jobs/{$job->id}", ['title' => 'Updated Title'])
            ->assertOk();
    }

    public function test_profile_update_does_not_affect_other_candidates_applications(): void
    {
        $candidateOne = User::factory()->create();
        $profileOne = CandidateProfile::factory()->create(['user_id' => $candidateOne->id, 'skills' => []]);

        $candidateTwo = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidateTwo->id, 'skills' => ['PHP']]);

        $job = JobPosting::factory()->create(['required_skills' => ['PHP']]);

        $applicationOne = Application::factory()->create(['candidate_id' => $candidateOne->id, 'job_id' => $job->id]);
        $applicationTwo = Application::factory()->create(['candidate_id' => $candidateTwo->id, 'job_id' => $job->id]);

        $scoreTwoBefore = $applicationTwo->fresh()->match_score;

        $profileOne->update(['skills' => ['PHP', 'React', 'AWS']]);

        $this->assertSame($scoreTwoBefore, $applicationTwo->fresh()->match_score);
    }
}
