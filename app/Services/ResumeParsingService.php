<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends an uploaded resume PDF to Gemini's document-understanding API and
 * returns suggested candidate-profile fields for the frontend to pre-fill —
 * this never persists anything itself, and never throws: any failure
 * (missing config, network error, an unexpected or malformed reply) is
 * logged and reported back as null, the same graceful-degradation shape as
 * GoogleCalendarService, so a flaky upstream call degrades to "fill this in
 * yourself" rather than a broken page.
 */
class ResumeParsingService
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    private const TIMEOUT_SECONDS = 45;

    private const PROMPT = <<<'PROMPT'
        You are extracting structured data from a candidate's resume for a job application form. Read the attached PDF and respond with the candidate's:
        - skills: an array of individual skill strings (e.g. "PHP", "Project Management"), drawn from anywhere in the resume.
        - education_level: the candidate's highest completed education level, as one of exactly "highschool", "bachelors", "masters", or "phd". Omit this field entirely if it cannot be determined from the resume.
        - years_experience: the candidate's total years of professional work experience, as a whole number. Estimate from listed job dates if not stated explicitly; use 0 if the resume shows no work experience.
        - resume_text: a clean, well-formatted plain-text summary of the candidate's professional background (experience, roles, achievements) suitable for storing as a free-text resume field. Do not include markdown formatting.
        PROMPT;

    /**
     * @return array{skills: array<int, string>, education_level: ?string, years_experience: int, resume_text: string}|null
     */
    public function parse(string $pdfContents): ?array
    {
        $apiKey = config('services.gemini.api_key');

        if (! $apiKey) {
            Log::warning('Gemini API key is not configured; skipping resume parsing.');

            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post(
                    sprintf(self::ENDPOINT, config('services.gemini.model')),
                    $this->buildRequestPayload($pdfContents),
                );
        } catch (Throwable $e) {
            Log::error('Gemini resume parsing request failed.', ['exception' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::error('Gemini resume parsing request returned an error response.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

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
     * @return array{skills: array<int, string>, education_level: ?string, years_experience: int, resume_text: string}|null
     */
    private function extractStructuredData(?array $body): ?array
    {
        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (! is_string($text) || $text === '') {
            Log::error('Gemini resume parsing response did not contain the expected text part.', ['body' => $body]);

            return null;
        }

        // response_mime_type: application/json should stop Gemini from
        // wrapping this in a markdown fence, but strip one defensively in
        // case a model revision reintroduces it.
        $text = trim(preg_replace('/^```(?:json)?|```$/m', '', $text));

        $data = json_decode($text, true);

        if (! is_array($data) || ! isset($data['skills'], $data['years_experience'], $data['resume_text'])) {
            Log::error('Gemini resume parsing response was not valid structured JSON.', ['text' => $text]);

            return null;
        }

        $educationLevel = $data['education_level'] ?? null;

        return [
            'skills' => array_values(array_filter(array_map('strval', (array) $data['skills']), fn ($skill) => $skill !== '')),
            'education_level' => in_array($educationLevel, ['highschool', 'bachelors', 'masters', 'phd'], true) ? $educationLevel : null,
            'years_experience' => max(0, (int) $data['years_experience']),
            'resume_text' => (string) $data['resume_text'],
        ];
    }
}
