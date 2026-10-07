<?php

namespace App\Services;

use App\Enums\ResumeParseOutcome;
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
 */
class ResumeParsingService
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    // Shorter than the old single 45s call: this is per attempt, and up to
    // three attempts at the primary model plus one at the fallback can now
    // happen in a single parse() call, bounded by TOTAL_TIME_BUDGET_SECONDS.
    private const PER_ATTEMPT_TIMEOUT_SECONDS = 30;

    // Across every attempt and backoff combined. A slow-but-eventually-
    // responding attempt can still run past this on its own (an in-flight
    // HTTP call can't be aborted mid-flight), but no *new* attempt or
    // backoff is started once it's exceeded.
    private const TOTAL_TIME_BUDGET_SECONDS = 60;

    // Only for a Busy outcome (429/503/timeout) — two retries at the
    // primary model, waited out before each, then one final attempt at the
    // fallback model if one is configured and still within budget.
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

        $deadline = microtime(true) + self::TOTAL_TIME_BUDGET_SECONDS;
        $primaryModel = config('services.gemini.model');

        // Order: primary (with its own retries), then each configured
        // fallback once, same busy handling — stop at the first one that
        // isn't Busy, or once the shared time budget runs out.
        $fallbackModels = array_values(array_filter([
            config('services.gemini.fallback_model'),
            config('services.gemini.fallback_model_2'),
        ]));

        $result = $this->attemptWithRetries($primaryModel, $pdfContents, $apiKey, $deadline);

        foreach ($fallbackModels as $fallbackModel) {
            if ($result->outcome !== ResumeParseOutcome::Busy || microtime(true) >= $deadline) {
                break;
            }

            $result = $this->attempt($fallbackModel, $pdfContents, $apiKey, 'fallback');
        }

        // Only here, once every model is exhausted — never on Unreadable
        // or Empty, which already have a real, correct answer.
        if (in_array($result->outcome, [ResumeParseOutcome::Busy, ResumeParseOutcome::Unavailable], true)) {
            return $this->localExtraction->extractAsFallback($pdfContents, $result->outcome);
        }

        return $result;
    }

    private function attemptWithRetries(string $model, string $pdfContents, string $apiKey, float $deadline): ResumeParseResult
    {
        $result = $this->attempt($model, $pdfContents, $apiKey, 1);

        foreach (self::RETRY_BACKOFF_SECONDS as $i => $backoffSeconds) {
            if ($result->outcome !== ResumeParseOutcome::Busy) {
                return $result;
            }

            if (microtime(true) + $backoffSeconds >= $deadline) {
                return $result;
            }

            Sleep::sleep($backoffSeconds);

            if (microtime(true) >= $deadline) {
                return $result;
            }

            $result = $this->attempt($model, $pdfContents, $apiKey, $i + 2);
        }

        return $result;
    }

    private function attempt(string $model, string $pdfContents, string $apiKey, int|string $attemptLabel): ResumeParseResult
    {
        $start = microtime(true);

        try {
            $response = Http::timeout(self::PER_ATTEMPT_TIMEOUT_SECONDS)
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post(
                    sprintf(self::ENDPOINT, $model),
                    $this->buildRequestPayload($pdfContents),
                );
        } catch (Throwable $e) {
            $this->logAttempt('warning', $model, $attemptLabel, $start, 'exception', $e->getMessage());

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
            $this->logAttempt('warning', $model, $attemptLabel, $start, (string) $status);

            return match (true) {
                in_array($status, [429, 503], true) => ResumeParseResult::busy(),
                $status === 400 => ResumeParseResult::unreadable(),
                default => ResumeParseResult::unavailable(),
            };
        }

        $this->logAttempt('info', $model, $attemptLabel, $start, '200');

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

    private function logAttempt(string $level, string $model, int|string $attemptLabel, float $start, string $status, ?string $exceptionMessage = null): void
    {
        $context = [
            'model' => $model,
            'attempt' => $attemptLabel,
            'duration_s' => round(microtime(true) - $start, 2),
            'status' => $status,
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
}
