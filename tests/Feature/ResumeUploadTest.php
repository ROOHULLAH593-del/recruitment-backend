<?php

namespace Tests\Feature;

use App\Models\CandidateProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsTestPdfs;
use Tests\TestCase;

class ResumeUploadTest extends TestCase
{
    use BuildsTestPdfs;
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
            'source' => 'ai',
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

    // --- Local, non-AI fallback ---

    private function uploadTextPdf(User $candidate, string $text): TestResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'resume').'.pdf';
        file_put_contents($path, $this->textPdf($text));

        try {
            return $this->actingAs($candidate, 'sanctum')->postJson('/api/profile/resume-upload', [
                'resume' => new UploadedFile($path, 'resume.pdf', 'application/pdf', null, true),
            ]);
        } finally {
            @unlink($path);
        }
    }

    public function test_every_model_staying_busy_falls_back_to_filling_in_what_local_extraction_can_read(): void
    {
        config(['services.gemini.fallback_model' => null, 'services.gemini.fallback_model_2' => null]);
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'overloaded'], 503)]);

        $response = $this->uploadTextPdf($candidate, '5 years of experience. Bachelor of Science. PHP, Laravel.');

        $response->assertOk()->assertJson([
            'source' => 'basic',
            'data' => [
                'education_level' => 'bachelors',
                'years_experience' => 5,
            ],
        ]);
        $this->assertContains('PHP', $response->json('data.skills'));
    }

    public function test_the_first_fallback_model_succeeding_is_still_reported_as_the_ai_source(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        config(['services.gemini.fallback_model' => 'gemini-3.8-flash']);
        Http::fake([
            '*/models/gemini-3.6-flash:generateContent*' => Http::response(['error' => 'overloaded'], 503),
            '*/models/gemini-3.8-flash:generateContent*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'skills' => ['PHP'], 'education_level' => 'bachelors', 'years_experience' => 4, 'resume_text' => 'x',
                ])]]]]],
            ]),
        ]);

        $response = $this->actingAs($candidate, 'sanctum')->postJson('/api/profile/resume-upload', [
            'resume' => UploadedFile::fake()->create('resume.pdf', 200, 'application/pdf'),
        ]);

        $response->assertOk()->assertJson(['source' => 'ai']);
    }

    public function test_a_persistently_overloaded_gemini_with_a_scanned_pdf_still_returns_busy_not_basic(): void
    {
        config(['services.gemini.fallback_model' => null, 'services.gemini.fallback_model_2' => null]);
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'overloaded'], 503)]);

        $path = tempnam(sys_get_temp_dir(), 'resume').'.pdf';
        file_put_contents($path, $this->scannedImageOnlyPdf());

        try {
            $response = $this->actingAs($candidate, 'sanctum')->postJson('/api/profile/resume-upload', [
                'resume' => new UploadedFile($path, 'resume.pdf', 'application/pdf', null, true),
            ]);
        } finally {
            @unlink($path);
        }

        $response->assertStatus(503)->assertJsonPath('category', 'busy');
    }

    public function test_an_encrypted_pdf_returns_unreadable_without_fabricating_fields_even_when_gemini_is_busy(): void
    {
        config(['services.gemini.fallback_model' => null, 'services.gemini.fallback_model_2' => null]);
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'overloaded'], 503)]);

        $path = tempnam(sys_get_temp_dir(), 'resume').'.pdf';
        file_put_contents($path, $this->encryptedPdf());

        try {
            $response = $this->actingAs($candidate, 'sanctum')->postJson('/api/profile/resume-upload', [
                'resume' => new UploadedFile($path, 'resume.pdf', 'application/pdf', null, true),
            ]);
        } finally {
            @unlink($path);
        }

        $response->assertStatus(422)->assertJsonPath('category', 'unreadable');
    }

    public function test_a_missing_api_key_with_a_real_text_pdf_fills_the_form_from_local_extraction(): void
    {
        config(['services.gemini.api_key' => null]);
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        Http::fake();

        $response = $this->uploadTextPdf($candidate, '2 years of experience. PHP.');

        $response->assertOk()->assertJson(['source' => 'basic']);
        Http::assertNothingSent();
    }

    public function test_local_extraction_finding_no_fields_still_returns_a_sensible_result_not_an_error(): void
    {
        config(['services.gemini.fallback_model' => null, 'services.gemini.fallback_model_2' => null]);
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'overloaded'], 503)]);

        $response = $this->uploadTextPdf($candidate, 'A short note with nothing recognizable in it at all.');

        $response->assertOk()->assertJson([
            'source' => 'basic',
            'data' => ['skills' => [], 'education_level' => null, 'years_experience' => null],
        ]);
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
