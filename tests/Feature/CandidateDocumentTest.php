<?php

namespace Tests\Feature;

use App\Models\CandidateProfile;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CandidateDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function documentTypeProvider(): array
    {
        return [
            'transcript' => ['transcript', 'transcript_path', 'transcript.pdf', 'application/pdf'],
            'cnic front' => ['cnic_front', 'cnic_front_path', 'cnic-front.jpg', 'image/jpeg'],
            'cnic back' => ['cnic_back', 'cnic_back_path', 'cnic-back.png', 'image/png'],
            'fsc certificate' => ['fsc_certificate', 'fsc_certificate_path', 'fsc.pdf', 'application/pdf'],
            'matric certificate' => ['matric_certificate', 'matric_certificate_path', 'matric.jpg', 'image/jpeg'],
        ];
    }

    #[DataProvider('documentTypeProvider')]
    public function test_candidate_can_upload_each_document_type(string $documentType, string $column, string $filename, string $mime): void
    {
        $candidate = User::factory()->create();
        $profile = CandidateProfile::factory()->create(['user_id' => $candidate->id]);

        $response = $this->actingAs($candidate, 'sanctum')->postJson('/api/profile/documents', [
            'document_type' => $documentType,
            'file' => UploadedFile::fake()->create($filename, 200, $mime),
        ]);

        $response->assertOk()->assertJsonPath("data.documents.{$documentType}", true);

        $profile->refresh();
        $this->assertNotNull($profile->{$column});
        Storage::disk('local')->assertExists($profile->{$column});
    }

    public function test_document_type_is_required(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);

        $response = $this->actingAs($candidate, 'sanctum')->postJson('/api/profile/documents', [
            'file' => UploadedFile::fake()->create('transcript.pdf', 200, 'application/pdf'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('document_type');
    }

    public function test_an_invalid_document_type_is_rejected(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);

        $response = $this->actingAs($candidate, 'sanctum')->postJson('/api/profile/documents', [
            'document_type' => 'passport',
            'file' => UploadedFile::fake()->create('transcript.pdf', 200, 'application/pdf'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('document_type');
    }

    public function test_file_is_required(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);

        $response = $this->actingAs($candidate, 'sanctum')->postJson('/api/profile/documents', [
            'document_type' => 'transcript',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_an_unsupported_file_type_is_rejected(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);

        $response = $this->actingAs($candidate, 'sanctum')->postJson('/api/profile/documents', [
            'document_type' => 'transcript',
            'file' => UploadedFile::fake()->create('transcript.docx', 200, 'application/msword'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_a_file_over_the_size_limit_is_rejected(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);

        $response = $this->actingAs($candidate, 'sanctum')->postJson('/api/profile/documents', [
            'document_type' => 'transcript',
            'file' => UploadedFile::fake()->create('transcript.pdf', 10241, 'application/pdf'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_staff_cannot_upload_a_document(): void
    {
        $hr = User::factory()->hr()->create();

        $response = $this->actingAs($hr, 'sanctum')->postJson('/api/profile/documents', [
            'document_type' => 'transcript',
            'file' => UploadedFile::fake()->create('transcript.pdf', 200, 'application/pdf'),
        ]);

        $response->assertStatus(403);
    }

    public function test_guest_cannot_upload_a_document(): void
    {
        $response = $this->postJson('/api/profile/documents', [
            'document_type' => 'transcript',
            'file' => UploadedFile::fake()->create('transcript.pdf', 200, 'application/pdf'),
        ]);

        $response->assertStatus(401);
    }

    public function test_replacing_a_document_deletes_the_old_file(): void
    {
        $candidate = User::factory()->create();
        $profile = CandidateProfile::factory()->create(['user_id' => $candidate->id]);

        $this->actingAs($candidate, 'sanctum')->postJson('/api/profile/documents', [
            'document_type' => 'transcript',
            'file' => UploadedFile::fake()->create('first.pdf', 200, 'application/pdf'),
        ])->assertOk();
        $firstPath = $profile->refresh()->transcript_path;
        Storage::disk('local')->assertExists($firstPath);

        $this->actingAs($candidate, 'sanctum')->postJson('/api/profile/documents', [
            'document_type' => 'transcript',
            'file' => UploadedFile::fake()->create('second.pdf', 200, 'application/pdf'),
        ])->assertOk();
        $secondPath = $profile->refresh()->transcript_path;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($secondPath);
    }

    // --- Apply-time completeness gate ---

    public function test_apply_is_blocked_with_a_clear_message_when_required_documents_are_missing(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        $job = JobPosting::factory()->create();

        $response = $this->actingAs($candidate, 'sanctum')->postJson("/api/jobs/{$job->id}/apply");

        $response->assertStatus(422)->assertJsonValidationErrors('documents');
        $message = $response->json('errors.documents.0');
        $this->assertStringContainsString('Transcript', $message);
        $this->assertStringContainsString('CNIC (front)', $message);
        $this->assertStringContainsString('CNIC (back)', $message);
    }

    public function test_apply_is_blocked_when_only_some_required_documents_are_missing(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->create([
            'user_id' => $candidate->id,
            'transcript_path' => 'candidate-documents/1/transcript.pdf',
            // cnic_front_path / cnic_back_path left null
        ]);
        $job = JobPosting::factory()->create();

        $response = $this->actingAs($candidate, 'sanctum')->postJson("/api/jobs/{$job->id}/apply");

        $response->assertStatus(422)->assertJsonValidationErrors('documents');
        $message = $response->json('errors.documents.0');
        $this->assertStringNotContainsString('Transcript', $message);
        $this->assertStringContainsString('CNIC (front)', $message);
        $this->assertStringContainsString('CNIC (back)', $message);
    }

    public function test_apply_succeeds_once_all_required_documents_are_present(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->withRequiredDocuments()->create(['user_id' => $candidate->id]);
        $job = JobPosting::factory()->create();

        $this->actingAs($candidate, 'sanctum')
            ->postJson("/api/jobs/{$job->id}/apply")
            ->assertCreated();
    }

    public function test_apply_gate_does_not_require_the_optional_certificates(): void
    {
        $candidate = User::factory()->create();
        CandidateProfile::factory()->withRequiredDocuments()->create([
            'user_id' => $candidate->id,
            'fsc_certificate_path' => null,
            'matric_certificate_path' => null,
        ]);
        $job = JobPosting::factory()->create();

        $this->actingAs($candidate, 'sanctum')
            ->postJson("/api/jobs/{$job->id}/apply")
            ->assertCreated();
    }

    // --- Authorization: viewing documents ---

    private function storeDocument(CandidateProfile $profile, string $column): string
    {
        $path = "candidate-documents/{$profile->id}/".str_replace('_path', '', $column).'.pdf';
        Storage::disk('local')->put($path, 'fake file contents');
        $profile->update([$column => $path]);

        return $path;
    }

    public function test_owner_can_view_their_own_transcript(): void
    {
        $candidate = User::factory()->create();
        $profile = CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        $this->storeDocument($profile, 'transcript_path');

        $this->actingAs($candidate, 'sanctum')
            ->get("/api/candidate-documents/{$profile->id}/transcript")
            ->assertOk();
    }

    public function test_owner_can_view_their_own_cnic_front(): void
    {
        $candidate = User::factory()->create();
        $profile = CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        $this->storeDocument($profile, 'cnic_front_path');

        $this->actingAs($candidate, 'sanctum')
            ->get("/api/candidate-documents/{$profile->id}/cnic_front")
            ->assertOk();
    }

    public function test_admin_can_view_any_document_including_cnic(): void
    {
        $admin = User::factory()->admin()->create();
        $candidate = User::factory()->create();
        $profile = CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        $this->storeDocument($profile, 'cnic_front_path');
        $this->storeDocument($profile, 'transcript_path');

        $this->actingAs($admin, 'sanctum')
            ->get("/api/candidate-documents/{$profile->id}/cnic_front")
            ->assertOk();
        $this->actingAs($admin, 'sanctum')
            ->get("/api/candidate-documents/{$profile->id}/transcript")
            ->assertOk();
    }

    #[DataProvider('nonCnicDocumentProvider')]
    public function test_hr_can_view_non_cnic_documents(string $documentType, string $column): void
    {
        $hr = User::factory()->hr()->create();
        $candidate = User::factory()->create();
        $profile = CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        $this->storeDocument($profile, $column);

        $this->actingAs($hr, 'sanctum')
            ->get("/api/candidate-documents/{$profile->id}/{$documentType}")
            ->assertOk();
    }

    #[DataProvider('nonCnicDocumentProvider')]
    public function test_assistant_hr_can_view_non_cnic_documents(string $documentType, string $column): void
    {
        $assistantHr = User::factory()->assistantHr()->create();
        $candidate = User::factory()->create();
        $profile = CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        $this->storeDocument($profile, $column);

        $this->actingAs($assistantHr, 'sanctum')
            ->get("/api/candidate-documents/{$profile->id}/{$documentType}")
            ->assertOk();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nonCnicDocumentProvider(): array
    {
        return [
            'transcript' => ['transcript', 'transcript_path'],
            'fsc certificate' => ['fsc_certificate', 'fsc_certificate_path'],
            'matric certificate' => ['matric_certificate', 'matric_certificate_path'],
        ];
    }

    #[DataProvider('cnicDocumentProvider')]
    public function test_hr_gets_403_on_cnic_documents(string $documentType, string $column): void
    {
        $hr = User::factory()->hr()->create();
        $candidate = User::factory()->create();
        $profile = CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        $this->storeDocument($profile, $column);

        $this->actingAs($hr, 'sanctum')
            ->get("/api/candidate-documents/{$profile->id}/{$documentType}")
            ->assertStatus(403);
    }

    #[DataProvider('cnicDocumentProvider')]
    public function test_assistant_hr_gets_403_on_cnic_documents(string $documentType, string $column): void
    {
        $assistantHr = User::factory()->assistantHr()->create();
        $candidate = User::factory()->create();
        $profile = CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        $this->storeDocument($profile, $column);

        $this->actingAs($assistantHr, 'sanctum')
            ->get("/api/candidate-documents/{$profile->id}/{$documentType}")
            ->assertStatus(403);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cnicDocumentProvider(): array
    {
        return [
            'cnic front' => ['cnic_front', 'cnic_front_path'],
            'cnic back' => ['cnic_back', 'cnic_back_path'],
        ];
    }

    public function test_another_candidate_cannot_view_someone_elses_documents(): void
    {
        $otherCandidate = User::factory()->create();
        $candidate = User::factory()->create();
        $profile = CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        $this->storeDocument($profile, 'transcript_path');
        $this->storeDocument($profile, 'cnic_front_path');

        $this->actingAs($otherCandidate, 'sanctum')
            ->get("/api/candidate-documents/{$profile->id}/transcript")
            ->assertStatus(403);
        $this->actingAs($otherCandidate, 'sanctum')
            ->get("/api/candidate-documents/{$profile->id}/cnic_front")
            ->assertStatus(403);
    }

    public function test_guest_cannot_view_any_document(): void
    {
        $candidate = User::factory()->create();
        $profile = CandidateProfile::factory()->create(['user_id' => $candidate->id]);
        $this->storeDocument($profile, 'transcript_path');

        $this->get("/api/candidate-documents/{$profile->id}/transcript")->assertStatus(401);
    }

    public function test_requesting_an_unfilled_document_slot_returns_404(): void
    {
        $candidate = User::factory()->create();
        $profile = CandidateProfile::factory()->create(['user_id' => $candidate->id]);

        $this->actingAs($candidate, 'sanctum')
            ->get("/api/candidate-documents/{$profile->id}/transcript")
            ->assertStatus(404);
    }
}
