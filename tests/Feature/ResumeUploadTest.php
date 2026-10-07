<?php

namespace Tests\Feature;

use App\Models\CandidateProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ResumeUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.gemini.api_key' => 'test-api-key', 'services.gemini.fallback_model' => null]);
        // Real backoffs (2s, 5s) would make the busy-response tests below
        // slow for no reason.
        Sleep::fake();
    }

    private function fakeSuccessfulGeminiResponse(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode([
                    'skills' => ['PHP', 'Laravel'],
                    'education_level' => 'bachelors',
                    'years_experience' => 4,
                    'resume_text' => 'A summary of the candidate.',
                ])]]],
            ]],
        ])]);
    }

    public function test_candidate_can_upload_a_resume_and_receive_suggested_fields(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id, 'skills' => ['Excel']]);
        $this->fakeSuccessfulGeminiResponse();

        $response = $this->actingAs($candidate, 'sanctum')->postJson('/api/profile/resume-upload', [
            'resume' => UploadedFile::fake()->create('resume.pdf', 200, 'application/pdf'),
        ]);

        $response->assertOk()->assertJson([
            'data' => [
                'skills' => ['PHP', 'Laravel'],
                'education_level' => 'bachelors',
                'years_experience' => 4,
                'resume_text' => 'A summary of the candidate.',
            ],
        ]);
    }

    public function test_uploading_a_resume_does_not_save_anything_to_the_profile(): void
    {
        $candidate = User::factory()->create();
        $profile = CandidateProfile::factory()->create(['user_id' => $candidate->id, 'skills' => ['Excel']]);
        $this->fakeSuccessfulGeminiResponse();

        $this->actingAs($candidate, 'sanctum')->postJson('/api/profile/resume-upload', [
            'resume' => UploadedFile::fake()->create('resume.pdf', 200, 'application/pdf'),
        ])->assertOk();

        $this->assertSame(['Excel'], $profile->fresh()->skills);
    }

    // --- One distinct response per failure category ---

    public function test_a_persistently_overloaded_gemini_returns_the_busy_category(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'overloaded'], 503)]);

        $response = $this->actingAs($candidate, 'sanctum')->postJson('/api/profile/resume-upload', [
            'resume' => UploadedFile::fake()->create('resume.pdf', 200, 'application/pdf'),
        ]);

        $response->assertStatus(503)->assertJson([
            'category' => 'busy',
            'message' => 'The AI service is busy right now. Please try again in a minute, or fill in your details below.',
        ]);
    }

    public function test_a_400_response_returns_the_unreadable_category(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'invalid argument'], 400)]);

        $response = $this->actingAs($candidate, 'sanctum')->postJson('/api/profile/resume-upload', [
            'resume' => UploadedFile::fake()->create('resume.pdf', 200, 'application/pdf'),
        ]);

        $response->assertStatus(422)->assertJson([
            'category' => 'unreadable',
            'message' => "We couldn't read this file. If it is password-protected, remove the password and try again, or fill in your details below.",
        ]);
    }

    public function test_a_response_with_nothing_extractable_returns_the_empty_category(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode([
                    'skills' => [], 'years_experience' => 0, 'resume_text' => '',
                ])]]],
            ]],
        ])]);

        $response = $this->actingAs($candidate, 'sanctum')->postJson('/api/profile/resume-upload', [
            'resume' => UploadedFile::fake()->create('resume.pdf', 200, 'application/pdf'),
        ]);

        $response->assertStatus(422)->assertJson([
            'category' => 'empty',
            'message' => "We couldn't find any details in this file. It may be a scan or an image. Please fill in your details below.",
        ]);
    }

    public function test_a_server_error_response_returns_the_unavailable_category(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'boom'], 500)]);

        $response = $this->actingAs($candidate, 'sanctum')->postJson('/api/profile/resume-upload', [
            'resume' => UploadedFile::fake()->create('resume.pdf', 200, 'application/pdf'),
        ]);

        $response->assertStatus(503)->assertJson([
            'category' => 'unavailable',
            'message' => 'Resume auto-fill is unavailable right now. Please fill in your details below.',
        ]);
    }

    public function test_a_missing_api_key_returns_the_unavailable_category(): void
    {
        config(['services.gemini.api_key' => null]);
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        Http::fake();

        $response = $this->actingAs($candidate, 'sanctum')->postJson('/api/profile/resume-upload', [
            'resume' => UploadedFile::fake()->create('resume.pdf', 200, 'application/pdf'),
        ]);

        $response->assertStatus(503)->assertJsonPath('category', 'unavailable');
        Http::assertNothingSent();
    }

    // --- Validation (unaffected by the category work) ---

    public function test_rejects_a_non_pdf_file(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);

        $response = $this->actingAs($candidate, 'sanctum')->postJson('/api/profile/resume-upload', [
            'resume' => UploadedFile::fake()->create('resume.docx', 200, 'application/msword'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('resume');
    }

    public function test_rejects_a_file_over_the_size_limit(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);

        $response = $this->actingAs($candidate, 'sanctum')->postJson('/api/profile/resume-upload', [
            'resume' => UploadedFile::fake()->create('resume.pdf', 6000, 'application/pdf'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('resume');
    }

    public function test_requires_a_resume_file(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);

        $this->actingAs($candidate, 'sanctum')->postJson('/api/profile/resume-upload', [])
            ->assertStatus(422)->assertJsonValidationErrors('resume');
    }

    public function test_staff_cannot_upload_a_resume(): void
    {
        $hr = User::factory()->hr()->create();

        $this->actingAs($hr, 'sanctum')->postJson('/api/profile/resume-upload', [
            'resume' => UploadedFile::fake()->create('resume.pdf', 200, 'application/pdf'),
        ])->assertStatus(403);
    }

    public function test_guest_cannot_upload_a_resume(): void
    {
        $this->postJson('/api/profile/resume-upload', [
            'resume' => UploadedFile::fake()->create('resume.pdf', 200, 'application/pdf'),
        ])->assertStatus(401);
    }
}
