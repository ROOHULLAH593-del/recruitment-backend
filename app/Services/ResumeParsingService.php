<?php

namespace App\Services;

use App\Enums\ResumeParseOutcome;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Sends an uploaded resume PDF to Gemini's document-understanding API and
 * returns suggested candidate-profile fields for the frontend to pre-fill —
 * this never persists anything itself, and never throws: any failure
 * (missing config, network error, an unexpected or malformed reply) is
 * logged and reported back as a ResumeParseResult, the same
 * graceful-degradation shape as GoogleCalendarService, so a flaky upstream
 * call degrades to "fill this in yourself" rather than a broken page.
 *
 * Confirmed via a live audit that Gemini's newer Flash models genuinely run
 * out of capacity (429/503, or a request that simply times out) a
 * meaningful fraction of the time — not something retrying forever fixes,
 * but something a couple of quick retries plus a couple of attempts on
 * different models noticeably improves. See ResumeParseOutcome for what a
 * caller can actually tell apart now instead of a bare null.
 *
 * If every model attempt still ends Busy or Unavailable, LocalResumeExtractionService
 * gets one try at filling the same fields straight from the PDF's own text,
 * no AI involved — confirmed live that even two Gemini models can both be
 * overloaded at once, and the upload shouldn't be a dead end when that happens.
 *
 * GEMINI_ATTEMPT_TIMEOUT and GEMINI_TOTAL_BUDGET (services.gemini.attempt_timeout
 * / total_budget) cap, respectively, a single HTTP attempt and the whole
 * chain (every attempt plus backoff wait, across every model) — a live log
 * showed a heavier model hang for the full 30s with nothing received, so
 * each attempt's own timeout is also capped by whatever of the total
 * budget is left, and no new attempt or backoff starts with under ~3s of
 * budget remaining. Once the budget is gone the chain stops right there
 * and falls through to the local text fallback same as running out of
 * models to try.
 */
class ResumeParsingService
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    // Below this much remaining budget, don't bother starting another
    // attempt (or the backoff sleep before one) — it's not enough time for
    // a real response, and every second here is a second not spent on the
    // local text fallback below.
    private const MIN_BUDGET_TO_START_SECONDS = 3.0;

    // Only for a Busy outcome (429/503/timeout) — two retries at the
    // primary model, waited out before each, then one final attempt at
    // each configured fallback model if still within budget.
    private const RETRY_BACKOFF_SECONDS = [2, 5];

    private const PROMPT = <<<'PROMPT'
        You are extracting structured data from a candidate's resume for a job application form. Read the attached PDF and respond with the candidate's:
        - skills: an array of individual skill strings (e.g. "PHP", "Project Management"), drawn from anywhere in the resume.
        - education_level: the candidate's highest completed education level, as one of exactly "highschool", "bachelors", "masters", or "phd". Omit this field entirely if it cannot be determined from the resume.
        - years_experience: the candidate's total years of professional work experience, as a whole number. Estimate from listed job dates if not stated explicitly; use 0 if the resume shows no work experience.
        - resume_text: a clean, well-formatted plain-text summary of the candidate's professional background (experience, roles, achievements) suitable for storing as a free-text resume field. Do not include markdown formatting.
        PROMPT;

    public function __construct(
        private readonly LocalResumeExtractionService $localExtraction = new LocalResumeExtractionService,
    ) {}

    public function parse(string $pdfContents): ResumeParseResult
    {
        $apiKey = config('services.gemini.api_key');

        if (! $apiKey) {
            Log::warning('Gemini API key is not configured; skipping resume parsing.');

            return $this->localExtraction->extractAsFallback($pdfContents, ResumeParseOutcome::Unavailable);
        }

        $attemptTimeout = (float) config('services.gemini.attempt_timeout', 15);
        $totalBudget = (float) config('services.gemini.total_budget', 35);
        $deadline = self::now() + $totalBudget;
        $primaryModel = config('services.gemini.model');

        // Order: primary (with its own retries), then each configured
        // fallback once, same busy handling — stop at the first one that
        // isn't Busy, or once the shared time budget runs out.
        $fallbackModels = array_values(array_filter([
            config('services.gemini.fallback_model'),
            config('services.gemini.fallback_model_2'),
        ]));

        $result = $this->attemptWithRetries($primaryModel, $pdfContents, $apiKey, $deadline, $attemptTimeout);

        foreach ($fallbackModels as $fallbackModel) {
            if ($result->outcome !== ResumeParseOutcome::Busy) {
                break;
            }

            $remaining = $deadline - self::now();

            if ($remaining < self::MIN_BUDGET_TO_START_SECONDS) {
                break;
            }

            $result = $this->attempt($fallbackModel, $pdfContents, $apiKey, 'fallback', self::cappedTimeout($attemptTimeout, $remaining), $remaining);
        }

        // Only here, once every model is exhausted (or the budget ran out
        // first) — never on Unreadable or Empty, which already have a
        // real, correct answer.
        if (in_array($result->outcome, [ResumeParseOutcome::Busy, ResumeParseOutcome::Unavailable], true)) {
            return $this->localExtraction->extractAsFallback($pdfContents, $result->outcome);
        }

        return $result;
    }

    private function attemptWithRetries(string $model, string $pdfContents, string $apiKey, float $deadline, float $attemptTimeout): ResumeParseResult
    {
        $remaining = $deadline - self::now();
        $result = $this->attempt($model, $pdfContents, $apiKey, 1, self::cappedTimeout($attemptTimeout, $remaining), $remaining);

        foreach (self::RETRY_BACKOFF_SECONDS as $i => $backoffSeconds) {
            if ($result->outcome !== ResumeParseOutcome::Busy) {
                return $result;
            }

            $remaining = $deadline - self::now();

            if ($remaining < $backoffSeconds + self::MIN_BUDGET_TO_START_SECONDS) {
                return $result;
            }

            Sleep::sleep($backoffSeconds);

            $remaining = $deadline - self::now();

            if ($remaining < self::MIN_BUDGET_TO_START_SECONDS) {
                return $result;
            }

            $result = $this->attempt($model, $pdfContents, $apiKey, $i + 2, self::cappedTimeout($attemptTimeout, $remaining), $remaining);
        }

        return $result;
    }

    private function attempt(string $model, string $pdfContents, string $apiKey, int|string $attemptLabel, float $timeoutSeconds, float $remainingBudgetSeconds): ResumeParseResult
    {
        $start = self::now();

        try {
            $response = Http::timeout($timeoutSeconds)
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post(
                    sprintf(self::ENDPOINT, $model),
                    $this->buildRequestPayload($pdfContents),
                );
        } catch (Throwable $e) {
            $this->logAttempt('warning', $model, $attemptLabel, $start, 'exception', $remainingBudgetSeconds, $e->getMessage());

            // A request that simply never got a response is the same
            // "try again" situation as a fast 503 — confirmed live:
            // Gemini's current overload shows up as both, for the same
            // underlying reason. Anything else (DNS, connection refused,
            // TLS) is a real local/network problem retrying won't fix.
            return str_contains(strtolower($e->getMessage()), 'timed out') || str_contains(strtolower($e->getMessage()), 'timeout')
                ? ResumeParseResult::busy()
                : ResumeParseResult::unavailable();
        }

        if ($response->failed()) {
            $status = $response->status();
            $this->logAttempt('warning', $model, $attemptLabel, $start, (string) $status, $remainingBudgetSeconds);

            return match (true) {
                in_array($status, [429, 503], true) => ResumeParseResult::busy(),
                $status === 400 => ResumeParseResult::unreadable(),
                default => ResumeParseResult::unavailable(),
            };
        }

        $this->logAttempt('info', $model, $attemptLabel, $start, '200', $remainingBudgetSeconds);

        return $this->extractStructuredData($response->json());
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRequestPayload(string $pdfContents): array
    {
        return [
            'contents' => [[
                'parts' => [
                    ['text' => self::PROMPT],
                    ['inline_data' => [
                        'mime_type' => 'application/pdf',
                        'data' => base64_encode($pdfContents),
                    ]],
                ],
            ]],
            'generationConfig' => [
                'response_mime_type' => 'application/json',
                'response_schema' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'skills' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                        'education_level' => ['type' => 'STRING', 'enum' => ['highschool', 'bachelors', 'masters', 'phd']],
                        'years_experience' => ['type' => 'INTEGER'],
                        'resume_text' => ['type' => 'STRING'],
                    ],
                    'required' => ['skills', 'years_experience', 'resume_text'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function extractStructuredData(?array $body): ResumeParseResult
    {
        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (! is_string($text) || $text === '') {
            $this->logMalformedResponse(
                'Gemini resume parsing response did not contain the expected text part.',
                json_encode($body) ?: '',
            );

            return ResumeParseResult::unavailable();
        }

        // response_mime_type: application/json should stop Gemini from
        // wrapping this in a markdown fence, but strip one defensively in
        // case a model revision reintroduces it.
        $cleaned = trim(preg_replace('/^```(?:json)?|```$/m', '', $text));

        $data = json_decode($cleaned, true);

        if (! is_array($data) || ! isset($data['skills'], $data['years_experience'], $data['resume_text'])) {
            $this->logMalformedResponse('Gemini resume parsing response was not valid structured JSON.', $cleaned);

            return ResumeParseResult::unavailable();
        }

        $educationLevel = $data['education_level'] ?? null;
        $skills = array_values(array_filter(array_map('strval', (array) $data['skills']), fn ($skill) => $skill !== ''));
        $resumeText = (string) $data['resume_text'];

        if (trim($resumeText) === '' && $skills === []) {
            return ResumeParseResult::empty();
        }

        return ResumeParseResult::ok([
            'skills' => $skills,
            'education_level' => in_array($educationLevel, ['highschool', 'bachelors', 'masters', 'phd'], true) ? $educationLevel : null,
            'years_experience' => max(0, (int) $data['years_experience']),
            'resume_text' => $resumeText,
        ]);
    }

    private function logAttempt(string $level, string $model, int|string $attemptLabel, float $start, string $status, float $remainingBudgetSeconds, ?string $exceptionMessage = null): void
    {
        $context = [
            'model' => $model,
            'attempt' => $attemptLabel,
            'duration_s' => round(self::now() - $start, 2),
            'status' => $status,
            'remaining_budget_s' => round($remainingBudgetSeconds, 2),
        ];

        if ($exceptionMessage !== null) {
            $context['exception'] = $exceptionMessage;
        }

        Log::log($level, 'Gemini resume parsing attempt.', $context);
    }

    /**
     * Logs enough to debug a shape/integration problem without ever
     * writing a candidate's actual extracted resume content (name, skills,
     * contact details) to the log file — found to be a real risk in the
     * previous version, which logged the full raw text/body here.
     */
    private function logMalformedResponse(string $message, string $raw): void
    {
        Log::error($message, [
            'length' => strlen($raw),
            'excerpt' => self::maskSensitive(mb_substr($raw, 0, 120)),
        ]);
    }

    private static function maskSensitive(string $text): string
    {
        $text = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/', '[email]', $text);

        return preg_replace('/\d/', 'X', $text);
    }

    /**
     * Wall-clock seconds, as a float. Goes through Carbon (rather than a
     * bare microtime(true)) solely so a test can control elapsed time
     * deterministically with Sleep::fake(syncWithCarbon: true) instead of
     * actually waiting out real backoffs to exercise the budget logic.
     */
    private static function now(): float
    {
        return Carbon::now()->getPreciseTimestamp(6) / 1_000_000;
    }

    /**
     * An attempt never gets longer than whatever's actually left of the
     * total budget — confirmed live that a hung attempt can otherwise burn
     * the whole budget on its own (30s with nothing received).
     */
    private static function cappedTimeout(float $attemptTimeout, float $remainingBudget): float
    {
        return min($attemptTimeout, $remainingBudget);
    }
}
