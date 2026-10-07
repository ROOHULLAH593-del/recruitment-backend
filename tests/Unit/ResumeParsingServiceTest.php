<?php

namespace Tests\Unit;

use App\Enums\ResumeParseOutcome;
use App\Services\ResumeParsingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsTestPdfs;
use Tests\TestCase;

class ResumeParsingServiceTest extends TestCase
{
    use BuildsTestPdfs;
    use RefreshDatabase;

    private ResumeParsingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ResumeParsingService;
        config([
            'services.gemini.api_key' => 'test-api-key',
            'services.gemini.model' => 'gemini-2.5-flash',
            'services.gemini.fallback_model' => null,
            'services.gemini.fallback_model_2' => null,
        ]);

        // Real backoffs (2s, 5s) would make every retry test slow for no
        // reason — Sleep::fake() lets the retry logic run for real without
        // actually waiting.
        Sleep::fake();
    }

    private function fakeGeminiResponse(array $body, int $status = 200): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($body, $status)]);
    }

    private function geminiTextResponse(string $text): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
    }

    private function okBody(array $overrides = []): array
    {
        return $this->geminiTextResponse(json_encode(array_merge([
            'skills' => ['PHP', 'Laravel', 'React'],
            'education_level' => 'bachelors',
            'years_experience' => 5,
            'resume_text' => 'Experienced backend engineer...',
        ], $overrides)));
    }

    // --- Basic outcomes ---

    public function test_returns_unavailable_when_api_key_is_not_configured(): void
    {
        config(['services.gemini.api_key' => null]);
        Http::fake();

        $result = $this->service->parse('%PDF-1.4 fake contents');

        $this->assertSame(ResumeParseOutcome::Unavailable, $result->outcome);
        Http::assertNothingSent();
    }

    public function test_returns_ok_with_structured_data_on_a_successful_response(): void
    {
        $this->fakeGeminiResponse($this->okBody());

        $result = $this->service->parse('%PDF-1.4 fake contents');

        $this->assertSame(ResumeParseOutcome::Ok, $result->outcome);
        $this->assertSame([
            'skills' => ['PHP', 'Laravel', 'React'],
            'education_level' => 'bachelors',
            'years_experience' => 5,
            'resume_text' => 'Experienced backend engineer...',
        ], $result->data);
    }

    public function test_sends_the_pdf_as_base64_inline_data_with_the_configured_model(): void
    {
        $this->fakeGeminiResponse($this->okBody(['skills' => [], 'years_experience' => 0, 'resume_text' => 'x']));

        $this->service->parse('raw-pdf-bytes');

        Http::assertSent(function ($request) {
            $part = $request->data()['contents'][0]['parts'][1]['inline_data'];

            return str_contains($request->url(), 'gemini-2.5-flash:generateContent')
                && $request->hasHeader('x-goog-api-key', 'test-api-key')
                && $part['mime_type'] === 'application/pdf'
                && base64_decode($part['data']) === 'raw-pdf-bytes';
        });
    }

    public function test_defaults_education_level_to_null_when_omitted(): void
    {
        $this->fakeGeminiResponse($this->geminiTextResponse(json_encode([
            'skills' => ['Sales'],
            'years_experience' => 2,
            'resume_text' => 'Some resume text.',
        ])));

        $result = $this->service->parse('contents');

        $this->assertNull($result->data['education_level']);
    }

    public function test_ignores_an_education_level_outside_the_known_enum_values(): void
    {
        $this->fakeGeminiResponse($this->okBody(['education_level' => 'doctorate'])); // not one of our enum's values

        $result = $this->service->parse('contents');

        $this->assertNull($result->data['education_level']);
    }

    public function test_strips_a_markdown_code_fence_defensively(): void
    {
        $json = json_encode(['skills' => [], 'years_experience' => 1, 'resume_text' => 'x']);
        $this->fakeGeminiResponse($this->geminiTextResponse("```json\n{$json}\n```"));

        $this->assertSame(ResumeParseOutcome::Ok, $this->service->parse('contents')->outcome);
    }

    // --- Empty: a valid response with nothing extractable ---

    public function test_returns_empty_when_nothing_extractable_came_back(): void
    {
        $this->fakeGeminiResponse($this->okBody(['skills' => [], 'resume_text' => '', 'years_experience' => 0]));

        $result = $this->service->parse('contents');

        $this->assertSame(ResumeParseOutcome::Empty, $result->outcome);
        $this->assertNull($result->data);
    }

    public function test_does_not_classify_as_empty_when_skills_were_found_even_without_resume_text(): void
    {
        $this->fakeGeminiResponse($this->okBody(['skills' => ['PHP'], 'resume_text' => '', 'years_experience' => 0]));

        $this->assertSame(ResumeParseOutcome::Ok, $this->service->parse('contents')->outcome);
    }

    // --- Unavailable: malformed / unexpected shape ---

    public function test_returns_unavailable_when_the_response_text_is_not_valid_json(): void
    {
        $this->fakeGeminiResponse($this->geminiTextResponse('not json at all'));

        $this->assertSame(ResumeParseOutcome::Unavailable, $this->service->parse('contents')->outcome);
    }

    public function test_returns_unavailable_when_the_response_is_missing_the_expected_shape(): void
    {
        $this->fakeGeminiResponse(['candidates' => []]);

        $this->assertSame(ResumeParseOutcome::Unavailable, $this->service->parse('contents')->outcome);
    }

    public function test_malformed_response_is_logged_without_the_actual_text(): void
    {
        Log::spy();

        $this->fakeGeminiResponse($this->geminiTextResponse('candidate email jane.doe@example.com, phone 555-123-4567, not json'));

        $this->service->parse('contents');

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) {
                $excerpt = $context['excerpt'] ?? '';

                return isset($context['length'])
                    && ! str_contains($excerpt, 'jane.doe@example.com')
                    && ! str_contains($excerpt, '555-123-4567')
                    && ! isset($context['text'])
                    && ! isset($context['body']);
            });
    }

    // --- Busy: 429 / 503 / timeout, all retryable ---

    public function test_returns_busy_on_a_429_response(): void
    {
        $this->fakeGeminiResponse(['error' => ['message' => 'rate limited']], 429);

        $this->assertSame(ResumeParseOutcome::Busy, $this->service->parse('contents')->outcome);
    }

    public function test_returns_busy_on_a_503_response(): void
    {
        $this->fakeGeminiResponse(['error' => ['message' => 'overloaded']], 503);

        $this->assertSame(ResumeParseOutcome::Busy, $this->service->parse('contents')->outcome);
    }

    public function test_returns_busy_when_the_request_times_out(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out after 30000 milliseconds');
        });

        $this->assertSame(ResumeParseOutcome::Busy, $this->service->parse('contents')->outcome);
    }

    public function test_returns_unavailable_when_the_request_throws_a_non_timeout_connection_error(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 6: Could not resolve host');
        });

        $this->assertSame(ResumeParseOutcome::Unavailable, $this->service->parse('contents')->outcome);
    }

    // --- Unreadable: 400 on an otherwise valid request ---

    public function test_returns_unreadable_on_a_400_response(): void
    {
        $this->fakeGeminiResponse(['error' => ['message' => 'invalid argument']], 400);

        $this->assertSame(ResumeParseOutcome::Unreadable, $this->service->parse('contents')->outcome);
    }

    public function test_does_not_retry_a_400_response(): void
    {
        $this->fakeGeminiResponse(['error' => ['message' => 'invalid argument']], 400);

        $this->service->parse('contents');

        Http::assertSentCount(1);
    }

    public function test_returns_unavailable_on_a_404_response_without_retrying(): void
    {
        $this->fakeGeminiResponse(['error' => ['message' => 'model not found']], 404);

        $result = $this->service->parse('contents');

        $this->assertSame(ResumeParseOutcome::Unavailable, $result->outcome);
        Http::assertSentCount(1);
    }

    // --- Retry behaviour (busy only) ---

    public function test_retries_a_busy_failure_and_succeeds_on_the_second_attempt(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['error' => ['message' => 'overloaded']], 503)
                ->push($this->okBody(), 200),
        ]);

        $result = $this->service->parse('contents');

        $this->assertSame(ResumeParseOutcome::Ok, $result->outcome);
        Http::assertSentCount(2);
        Sleep::assertSlept(fn ($duration) => $duration->totalSeconds == 2, times: 1);
    }

    public function test_gives_up_as_busy_after_exhausting_both_retries(): void
    {
        $this->fakeGeminiResponse(['error' => ['message' => 'overloaded']], 503);

        $result = $this->service->parse('contents');

        $this->assertSame(ResumeParseOutcome::Busy, $result->outcome);
        // 1 initial attempt + 2 retries = 3 total.
        Http::assertSentCount(3);
        Sleep::assertSequence([
            Sleep::for(2)->seconds(),
            Sleep::for(5)->seconds(),
        ]);
    }

    // --- Fallback model ---

    public function test_falls_back_to_the_configured_model_after_the_primary_stays_busy(): void
    {
        config(['services.gemini.fallback_model' => 'gemini-3.8-flash']);

        Http::fake([
            '*/models/gemini-2.5-flash:generateContent*' => Http::response(['error' => ['message' => 'overloaded']], 503),
            '*/models/gemini-3.8-flash:generateContent*' => Http::response($this->okBody(), 200),
        ]);

        $result = $this->service->parse('contents');

        $this->assertSame(ResumeParseOutcome::Ok, $result->outcome);
        // 3 primary attempts + 1 fallback attempt.
        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'gemini-3.8-flash:generateContent'));
    }

    public function test_does_not_use_the_fallback_model_when_the_primary_succeeds(): void
    {
        config(['services.gemini.fallback_model' => 'gemini-3.8-flash']);

        Http::fake([
            '*/models/gemini-2.5-flash:generateContent*' => Http::response($this->okBody(), 200),
            '*/models/gemini-3.8-flash:generateContent*' => Http::response($this->okBody(), 200),
        ]);

        $result = $this->service->parse('contents');

        $this->assertSame(ResumeParseOutcome::Ok, $result->outcome);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'gemini-3.8-flash:generateContent'));
    }

    public function test_does_not_use_the_fallback_model_when_the_primary_fails_as_unreadable(): void
    {
        config(['services.gemini.fallback_model' => 'gemini-3.8-flash']);

        Http::fake([
            '*/models/gemini-2.5-flash:generateContent*' => Http::response(['error' => ['message' => 'invalid']], 400),
            '*/models/gemini-3.8-flash:generateContent*' => Http::response($this->okBody(), 200),
        ]);

        $result = $this->service->parse('contents');

        $this->assertSame(ResumeParseOutcome::Unreadable, $result->outcome);
        Http::assertSentCount(1);
    }

    public function test_remains_busy_when_no_fallback_model_is_configured(): void
    {
        $this->assertNull(config('services.gemini.fallback_model'));
        $this->fakeGeminiResponse(['error' => ['message' => 'overloaded']], 503);

        $result = $this->service->parse('contents');

        $this->assertSame(ResumeParseOutcome::Busy, $result->outcome);
        Http::assertSentCount(3);
    }

    // --- Second fallback model ---

    public function test_falls_back_to_the_second_configured_model_when_the_first_fallback_also_stays_busy(): void
    {
        config(['services.gemini.fallback_model' => 'gemini-3.8-flash', 'services.gemini.fallback_model_2' => 'gemini-3.5-flash-lite']);

        Http::fake([
            '*/models/gemini-2.5-flash:generateContent*' => Http::response(['error' => ['message' => 'overloaded']], 503),
            '*/models/gemini-3.8-flash:generateContent*' => Http::response(['error' => ['message' => 'overloaded']], 503),
            '*/models/gemini-3.5-flash-lite:generateContent*' => Http::response($this->okBody(), 200),
        ]);

        $result = $this->service->parse('contents');

        $this->assertSame(ResumeParseOutcome::Ok, $result->outcome);
        $this->assertSame('ai', $result->source);
        // 3 primary attempts + 1 first-fallback attempt + 1 second-fallback attempt.
        Http::assertSentCount(5);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'gemini-3.5-flash-lite:generateContent'));
    }

    public function test_does_not_use_the_second_fallback_model_when_the_first_fallback_succeeds(): void
    {
        config(['services.gemini.fallback_model' => 'gemini-3.8-flash', 'services.gemini.fallback_model_2' => 'gemini-3.5-flash-lite']);

        Http::fake([
            '*/models/gemini-2.5-flash:generateContent*' => Http::response(['error' => ['message' => 'overloaded']], 503),
            '*/models/gemini-3.8-flash:generateContent*' => Http::response($this->okBody(), 200),
            '*/models/gemini-3.5-flash-lite:generateContent*' => Http::response($this->okBody(), 200),
        ]);

        $result = $this->service->parse('contents');

        $this->assertSame(ResumeParseOutcome::Ok, $result->outcome);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'gemini-3.5-flash-lite:generateContent'));
    }

    // --- Local, non-AI fallback (after the whole model chain is exhausted) ---

    public function test_falls_back_to_local_extraction_when_every_model_in_the_chain_stays_busy(): void
    {
        config(['services.gemini.fallback_model' => 'gemini-3.8-flash', 'services.gemini.fallback_model_2' => 'gemini-3.5-flash-lite']);
        $this->fakeGeminiResponse(['error' => ['message' => 'overloaded']], 503);

        $result = $this->service->parse($this->textPdf('5 years of experience. Bachelor of Science. PHP skills.'));

        $this->assertSame(ResumeParseOutcome::Ok, $result->outcome);
        $this->assertSame('basic', $result->source);
        $this->assertSame(5, $result->data['years_experience']);
    }

    public function test_does_not_run_local_extraction_when_the_ai_call_succeeds(): void
    {
        $this->fakeGeminiResponse($this->okBody());

        $result = $this->service->parse('contents');

        $this->assertSame('ai', $result->source);
    }

    public function test_falls_back_to_local_extraction_directly_when_the_api_key_is_missing(): void
    {
        config(['services.gemini.api_key' => null]);
        Http::fake();

        $result = $this->service->parse($this->textPdf('3 years of experience. PHP, Laravel.'));

        $this->assertSame(ResumeParseOutcome::Ok, $result->outcome);
        $this->assertSame('basic', $result->source);
        Http::assertNothingSent();
    }

    public function test_does_not_run_local_extraction_when_the_primary_fails_as_unreadable(): void
    {
        $this->fakeGeminiResponse(['error' => ['message' => 'invalid']], 400);

        $result = $this->service->parse($this->textPdf('5 years of experience.'));

        $this->assertSame(ResumeParseOutcome::Unreadable, $result->outcome);
        $this->assertNull($result->data);
    }

    // --- Time budget ---

    public function test_caps_each_attempts_timeout_by_the_remaining_budget(): void
    {
        $cappedTimeout = new \ReflectionMethod(ResumeParsingService::class, 'cappedTimeout');

        $this->assertSame(10.0, $cappedTimeout->invoke(null, 15.0, 10.0));
        $this->assertSame(15.0, $cappedTimeout->invoke(null, 15.0, 20.0));
    }

    public function test_stops_the_model_chain_once_the_total_budget_is_spent_and_falls_back_locally(): void
    {
        config(['services.gemini.total_budget' => 1, 'services.gemini.fallback_model' => 'gemini-3.8-flash']);
        $this->fakeGeminiResponse(['error' => ['message' => 'overloaded']], 503);

        $result = $this->service->parse($this->textPdf('5 years of experience. PHP.'));

        // Budget (1s) is already below the 3s floor after the very first
        // attempt, so neither a primary retry nor the fallback model ever starts.
        Http::assertSentCount(1);
        $this->assertSame(ResumeParseOutcome::Ok, $result->outcome);
        $this->assertSame('basic', $result->source);
    }

    public function test_a_scan_still_reports_busy_rather_than_basic_once_the_budget_is_spent(): void
    {
        config(['services.gemini.total_budget' => 1]);
        $this->fakeGeminiResponse(['error' => ['message' => 'overloaded']], 503);

        $result = $this->service->parse($this->scannedImageOnlyPdf());

        $this->assertSame(ResumeParseOutcome::Busy, $result->outcome);
    }

    public function test_does_not_start_a_retry_once_its_backoff_would_exceed_the_remaining_budget(): void
    {
        // Sleep::fake(syncWithCarbon: true) makes a faked Sleep::sleep()
        // actually advance Carbon's test time by the slept duration, so the
        // budget math below plays out deterministically without a real wait.
        Sleep::fake(syncWithCarbon: true);
        config(['services.gemini.total_budget' => 9]);
        $this->fakeGeminiResponse(['error' => ['message' => 'overloaded']], 503);

        $result = $this->service->parse('contents');

        // t=0: attempt 1 (busy). remaining=9 >= 2+3, sleep 2s -> t=2.
        // t=2: attempt 2 (busy). remaining=7 < 5+3, so no second backoff/attempt.
        $this->assertSame(ResumeParseOutcome::Busy, $result->outcome);
        Http::assertSentCount(2);
        Sleep::assertSlept(fn ($duration) => $duration->totalSeconds == 2, times: 1);
    }

    public function test_a_healthy_primary_model_succeeds_on_the_first_attempt(): void
    {
        $this->fakeGeminiResponse($this->okBody());

        $result = $this->service->parse('contents');

        $this->assertSame(ResumeParseOutcome::Ok, $result->outcome);
        $this->assertSame('ai', $result->source);
        Http::assertSentCount(1);
    }

    // --- Attempt logging (no resume content) ---

    public function test_logs_each_attempt_with_model_status_and_duration(): void
    {
        Log::spy();

        $this->fakeGeminiResponse($this->okBody());

        $this->service->parse('contents');

        Log::shouldHaveReceived('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $level === 'info'
                && $context['model'] === 'gemini-2.5-flash'
                && $context['attempt'] === 1
                && $context['status'] === '200'
                && isset($context['duration_s'])
                && isset($context['remaining_budget_s'])
            );
    }
}
