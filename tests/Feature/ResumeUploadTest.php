<?php

namespace Tests\Feature;

use App\Models\CandidateProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResumeUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.gemini.api_key' => 'test-api-key']);
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

    public function test_returns_a_clear_error_when_gemini_fails(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'boom'], 500)]);

        $response = $this->actingAs($candidate, 'sanctum')->postJson('/api/profile/resume-upload', [
            'resume' => UploadedFile::fake()->create('resume.pdf', 200, 'application/pdf'),
        ]);

        $response->assertStatus(503)->assertJsonStructure(['message']);
    }

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
