<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SemanticMatchScoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.gemini.api_key' => 'test-api-key']);
    }

    private function fakeEmbeddings(array $candidateVector, array $jobVector, int $status = 200): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'embeddings' => [
                ['values' => $candidateVector],
                ['values' => $jobVector],
            ],
        ], $status)]);
    }

    /**
     * Queue two successive embedding responses — separate Http::fake() calls
     * don't reliably override each other for the same URL pattern within one
     * test, so a real sequence is needed when a test needs the response to
     * change between the create-time call and a later recalculation call.
     */
    private function fakeEmbeddingSequence(array $first, array $second): void
    {
        Http::fakeSequence('generativelanguage.googleapis.com/*')
            ->push(['embeddings' => [['values' => $first[0]], ['values' => $first[1]]]])
            ->push(['embeddings' => [['values' => $second[0]], ['values' => $second[1]]]]);
    }

    public function test_semantic_match_score_is_calculated_on_application_creation(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->withRequiredDocuments()->create(['user_id' => $candidate->id, 'resume_text' => 'Experienced PHP developer.']);
        $job = JobPosting::factory()->create(['description' => 'Looking for a PHP developer.']);
        $this->fakeEmbeddings([1.0, 0.0], [1.0, 0.0]);

        $this->actingAs($candidate, 'sanctum')->postJson("/api/jobs/{$job->id}/apply")->assertCreated();

        $application = Application::where('candidate_id', $candidate->id)->where('job_id', $job->id)->first();
        $this->assertSame('100.00', $application->semantic_match_score);
    }

    public function test_application_creation_succeeds_even_when_gemini_fails(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->withRequiredDocuments()->create([
            'user_id' => $candidate->id,
            'resume_text' => 'Experienced PHP developer.',
            'skills' => ['PHP'],
            'years_experience' => 0,
            'education_level' => null,
        ]);
        $job = JobPosting::factory()->create([
            'description' => 'Looking for a PHP developer.',
            'required_skills' => ['PHP'],
            'min_experience' => 0,
            'education_requirement' => null,
        ]);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'boom'], 503)]);

        $this->actingAs($candidate, 'sanctum')->postJson("/api/jobs/{$job->id}/apply")->assertCreated();

        $application = Application::where('candidate_id', $candidate->id)->where('job_id', $job->id)->first();
        // The rule-based score (independent of Gemini) still computed normally...
        $this->assertSame('100.00', $application->match_score);
        // ...while the AI score gracefully degrades to null instead of blocking creation.
        $this->assertNull($application->semantic_match_score);
    }

    public function test_updating_a_candidate_profile_recalculates_semantic_match_score(): void
    {
        $candidate = User::factory()->create();
        $profile = CandidateProfile::factory()->create(['user_id' => $candidate->id, 'resume_text' => 'Initial resume text.']);
        $job = JobPosting::factory()->create();
        $this->fakeEmbeddingSequence([[1.0, 0.0], [0.0, 1.0]], [[1.0, 0.0], [1.0, 0.0]]);
        $application = Application::factory()->create(['candidate_id' => $candidate->id, 'job_id' => $job->id]);

        $this->assertSame('0.00', $application->fresh()->semantic_match_score);

        $profile->update(['resume_text' => 'A much better matching resume.']);

        $this->assertSame('100.00', $application->fresh()->semantic_match_score);
    }

    public function test_updating_a_job_postings_requirements_recalculates_semantic_match_score(): void
    {
        $hr = User::factory()->hr()->create();
        $job = JobPosting::factory()->create(['posted_by' => $hr->id, 'description' => 'Original description.']);
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id, 'resume_text' => 'A resume.']);
        $this->fakeEmbeddingSequence([[1.0, 0.0], [0.0, 1.0]], [[1.0, 0.0], [1.0, 0.0]]);
        $application = Application::factory()->create(['candidate_id' => $candidate->id, 'job_id' => $job->id]);

        $this->assertSame('0.00', $application->fresh()->semantic_match_score);

        $this->actingAs($hr, 'sanctum')->putJson("/api/jobs/{$job->id}", ['description' => 'Updated description.'])->assertOk();

        $this->assertSame('100.00', $application->fresh()->semantic_match_score);
    }

    public function test_application_resource_exposes_semantic_match_score(): void
    {
        $hr = User::factory()->hr()->create();
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id, 'resume_text' => 'A resume.']);
        $job = JobPosting::factory()->create();
        $this->fakeEmbeddings([1.0, 0.0], [1.0, 0.0]);
        $application = Application::factory()->create(['candidate_id' => $candidate->id, 'job_id' => $job->id]);

        $this->actingAs($hr, 'sanctum')
            ->getJson("/api/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.semantic_match_score', '100.00');
    }

    public function test_application_resource_returns_null_semantic_match_score_when_unavailable(): void
    {
        $hr = User::factory()->hr()->create();
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id, 'resume_text' => 'A resume.']);
        $job = JobPosting::factory()->create();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'boom'], 503)]);
        $application = Application::factory()->create(['candidate_id' => $candidate->id, 'job_id' => $job->id]);

        $this->actingAs($hr, 'sanctum')
            ->getJson("/api/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.semantic_match_score', null);
    }
}
