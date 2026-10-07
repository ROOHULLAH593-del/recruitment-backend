<?php

namespace Tests\Unit;

use App\Enums\ResumeParseOutcome;
use App\Models\JobPosting;
use App\Services\LocalResumeExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\BuildsTestPdfs;
use Tests\TestCase;

class LocalResumeExtractionServiceTest extends TestCase
{
    use BuildsTestPdfs;
    use RefreshDatabase;

    private LocalResumeExtractionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LocalResumeExtractionService;
    }

    public function test_fills_the_same_fields_the_ai_path_fills_from_real_text(): void
    {
        $pdf = $this->textPdf(<<<'TEXT'
            Jordan Casey Smith

            EXPERIENCE
            5 years of experience in backend development.

            EDUCATION
            Bachelor of Science in Computer Science.

            SKILLS
            PHP, Laravel, Customer Service
            TEXT);

        $result = $this->service->extractAsFallback($pdf, ResumeParseOutcome::Busy);

        $this->assertSame(ResumeParseOutcome::Ok, $result->outcome);
        $this->assertSame('basic', $result->source);
        $this->assertSame(5, $result->data['years_experience']);
        $this->assertSame('bachelors', $result->data['education_level']);
        $this->assertContains('PHP', $result->data['skills']);
        $this->assertContains('Laravel', $result->data['skills']);
        $this->assertContains('Customer Service', $result->data['skills']);
        $this->assertStringContainsString('Jordan Casey Smith', $result->data['resume_text']);
    }

    public function test_matches_skills_from_existing_job_postings_required_skills(): void
    {
        JobPosting::factory()->create(['required_skills' => ['Forklift Operation', 'Inventory Management']]);
        $pdf = $this->textPdf('Experienced in Forklift Operation and warehouse work.');

        $result = $this->service->extractAsFallback($pdf, ResumeParseOutcome::Busy);

        $this->assertSame('basic', $result->source);
        $this->assertContains('Forklift Operation', $result->data['skills']);
        $this->assertNotContains('Inventory Management', $result->data['skills']);
    }

    public function test_detects_the_highest_education_level_mentioned(): void
    {
        $pdf = $this->textPdf('Holds a Bachelor of Science and later a Master of Business Administration (MBA).');

        $result = $this->service->extractAsFallback($pdf, ResumeParseOutcome::Busy);

        $this->assertSame('masters', $result->data['education_level']);
    }

    public function test_does_not_guess_years_of_experience_from_date_ranges(): void
    {
        $pdf = $this->textPdf('Acme Corp, 2015 - 2023. Senior Developer.');

        $result = $this->service->extractAsFallback($pdf, ResumeParseOutcome::Busy);

        $this->assertNull($result->data['years_experience']);
    }

    public function test_returns_a_sensible_empty_result_rather_than_an_error_when_nothing_matches(): void
    {
        $pdf = $this->textPdf('A short note with no recognizable resume details whatsoever here at all.');

        $result = $this->service->extractAsFallback($pdf, ResumeParseOutcome::Busy);

        $this->assertSame(ResumeParseOutcome::Ok, $result->outcome);
        $this->assertSame('basic', $result->source);
        $this->assertSame([], $result->data['skills']);
        $this->assertNull($result->data['education_level']);
        $this->assertNull($result->data['years_experience']);
        $this->assertNotSame('', $result->data['resume_text']);
    }

    // --- Never fabricates ---

    public function test_a_scanned_image_only_pdf_keeps_the_original_busy_outcome(): void
    {
        $result = $this->service->extractAsFallback($this->scannedImageOnlyPdf(), ResumeParseOutcome::Busy);

        $this->assertSame(ResumeParseOutcome::Busy, $result->outcome);
        $this->assertNull($result->data);
    }

    public function test_a_scanned_image_only_pdf_keeps_the_original_unavailable_outcome(): void
    {
        $result = $this->service->extractAsFallback($this->scannedImageOnlyPdf(), ResumeParseOutcome::Unavailable);

        $this->assertSame(ResumeParseOutcome::Unavailable, $result->outcome);
        $this->assertNull($result->data);
    }

    public function test_an_encrypted_pdf_is_reported_as_unreadable_regardless_of_the_ai_outcome(): void
    {
        $result = $this->service->extractAsFallback($this->encryptedPdf(), ResumeParseOutcome::Busy);

        $this->assertSame(ResumeParseOutcome::Unreadable, $result->outcome);
        $this->assertNull($result->data);
    }

    // --- Safety limits ---

    public function test_only_reads_the_first_ten_pages(): void
    {
        $pdf = $this->manyPagePdf(15);

        $result = $this->service->extractAsFallback($pdf, ResumeParseOutcome::Busy);

        $this->assertStringContainsString('PAGE_MARKER_10', $result->data['resume_text']);
        $this->assertStringNotContainsString('PAGE_MARKER_11', $result->data['resume_text']);
        $this->assertStringNotContainsString('PAGE_MARKER_15', $result->data['resume_text']);
    }

    public function test_caps_extracted_text_length(): void
    {
        $longText = trim(str_repeat("Experienced customer service professional with strong communication skills.\n", 400)); // well over 20,000 chars
        $pdf = $this->textPdf($longText);

        $result = $this->service->extractAsFallback($pdf, ResumeParseOutcome::Busy);

        $this->assertLessThanOrEqual(20000, mb_strlen($result->data['resume_text']));
    }

    // --- Privacy ---

    public function test_logs_contain_no_resume_text_or_extracted_values(): void
    {
        Log::spy();

        $pdf = $this->textPdf('Contact: jane.doe@example.com or 555-123-4567. 5 years of experience. Bachelor of Science. PHP, Laravel.');

        $this->service->extractAsFallback($pdf, ResumeParseOutcome::Busy);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context) {
                $json = json_encode($context);

                return ! str_contains($json, 'jane.doe@example.com')
                    && ! str_contains($json, '555-123-4567')
                    && ! str_contains($json, 'PHP')
                    && ! str_contains($json, 'Bachelor')
                    && isset($context['fields_filled'])
                    && isset($context['duration_s'])
                    && isset($context['page_count'])
                    && isset($context['text_length']);
            });
    }

    public function test_does_not_log_which_ai_outcome_it_is_keeping_as_an_error(): void
    {
        Log::spy();

        $this->service->extractAsFallback($this->scannedImageOnlyPdf(), ResumeParseOutcome::Busy);

        Log::shouldNotHaveReceived('error');
    }
}
