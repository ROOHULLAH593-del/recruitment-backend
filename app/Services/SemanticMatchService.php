<?php

namespace App\Services;

use App\Models\CandidateProfile;
use App\Models\JobPosting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A second, independent match score alongside MatchScoreService's rule-based
 * one: embeds the candidate's resume text and the job's description/required
 * skills with Gemini, then scores their cosine similarity 0-100. Deliberately
 * synchronous rather than queued (no queue worker runs in this environment)
 * with a short timeout, so this can sit directly in front of application
 * creation without a slow or flaky embedding call blocking it.
 *
 * Never throws: any failure (missing config, network error, an unexpected or
 * malformed reply) is logged and reported back as null — the same
 * graceful-degradation shape as GoogleCalendarService and
 * ResumeParsingService. Callers must treat null as "no AI score yet", not an
 * error to surface to the user.
 */
class SemanticMatchService
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:batchEmbedContents';

    // Embeddings are a much lighter call than resume parsing's document
    // understanding — kept short so a slow Gemini response never makes
    // application creation itself feel slow.
    private const TIMEOUT_SECONDS = 15;

    /**
     * Score a candidate's resume against a job posting, 0-100, or null if it
     * can't be computed right now (missing config/text, or the API call failed).
     */
    public function score(CandidateProfile $profile, JobPosting $job): ?float
    {
        $apiKey = config('services.gemini.api_key');

        if (! $apiKey) {
            Log::warning('Gemini API key is not configured; skipping semantic match scoring.');

            return null;
        }

        $candidateText = trim($profile->resume_text ?? '');
        $jobText = $this->buildJobText($job);

        if ($candidateText === '' || $jobText === '') {
            // Nothing meaningful to embed yet (e.g. no resume text) — not a
            // failure, just not scoreable yet.
            return null;
        }

        $model = config('services.gemini.embedding_model');

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post(sprintf(self::ENDPOINT, $model), [
                    'requests' => [
                        ['model' => "models/{$model}", 'content' => ['parts' => [['text' => $candidateText]]]],
                        ['model' => "models/{$model}", 'content' => ['parts' => [['text' => $jobText]]]],
                    ],
                ]);
        } catch (Throwable $e) {
            Log::error('Gemini semantic match request failed.', ['exception' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::error('Gemini semantic match request returned an error response.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $this->cosineSimilarityScore($response->json('embeddings'));
    }

    private function buildJobText(JobPosting $job): string
    {
        $text = trim((string) $job->description);
        $skills = $job->required_skills ?? [];

        if ($skills !== []) {
            $text .= "\n\nRequired skills: ".implode(', ', $skills);
        }

        return trim($text);
    }

    /**
     * @param  array<int, array{values?: array<int, float>}>|null  $embeddings
     */
    private function cosineSimilarityScore(?array $embeddings): ?float
    {
        $candidateVector = $embeddings[0]['values'] ?? null;
        $jobVector = $embeddings[1]['values'] ?? null;

        if (
            ! is_array($candidateVector) || ! is_array($jobVector)
            || $candidateVector === [] || count($candidateVector) !== count($jobVector)
        ) {
            Log::error('Gemini semantic match response did not contain two matching embedding vectors.', [
                'embeddings' => $embeddings,
            ]);

            return null;
        }

        $dotProduct = 0.0;
        $candidateMagnitude = 0.0;
        $jobMagnitude = 0.0;

        foreach ($candidateVector as $i => $value) {
            $dotProduct += $value * $jobVector[$i];
            $candidateMagnitude += $value ** 2;
            $jobMagnitude += $jobVector[$i] ** 2;
        }

        if ($candidateMagnitude <= 0.0 || $jobMagnitude <= 0.0) {
            return 0.0;
        }

        $similarity = $dotProduct / (sqrt($candidateMagnitude) * sqrt($jobMagnitude));

        // Cosine similarity for related text embeddings is virtually always
        // positive in practice; clamp to 0 rather than letting a near-zero
        // negative value read as a meaningful "opposite" score.
        return round(max(0.0, min(1.0, $similarity)) * 100, 2);
    }
}
