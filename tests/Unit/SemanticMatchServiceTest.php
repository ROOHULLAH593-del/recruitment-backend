<?php

namespace Tests\Unit;

use App\Models\CandidateProfile;
use App\Models\JobPosting;
use App\Services\SemanticMatchService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SemanticMatchServiceTest extends TestCase
{
    private SemanticMatchService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SemanticMatchService;
        config(['services.gemini.api_key' => 'test-api-key', 'services.gemini.embedding_model' => 'gemini-embedding-2']);
    }

    private function fakeEmbeddings(array $candidateVector, array $jobVector, int $status = 200): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'embeddings' => [
                ['values' => $candidateVector],
                ['values' => $jobVector],
            ],
        ], $status)]);
    }

    private function profile(string $resumeText = 'Backend engineer with PHP and Laravel experience.'): CandidateProfile
    {
        return new CandidateProfile(['resume_text' => $resumeText]);
    }

    private function job(string $description = 'Looking for a PHP developer.', array $skills = ['PHP']): JobPosting
    {
        return new JobPosting(['description' => $description, 'required_skills' => $skills]);
    }

    public function test_returns_null_when_api_key_is_not_configured(): void
    {
        config(['services.gemini.api_key' => null]);
        Http::fake();

        $this->assertNull($this->service->score($this->profile(), $this->job()));
        Http::assertNothingSent();
    }

    public function test_returns_null_when_resume_text_is_empty(): void
    {
        Http::fake();

        $this->assertNull($this->service->score($this->profile(''), $this->job()));
        Http::assertNothingSent();
    }

    public function test_returns_100_for_identical_vectors(): void
    {
        $this->fakeEmbeddings([1.0, 0.0, 0.0], [1.0, 0.0, 0.0]);

        $this->assertSame(100.0, $this->service->score($this->profile(), $this->job()));
    }

    public function test_returns_0_for_orthogonal_vectors(): void
    {
        $this->fakeEmbeddings([1.0, 0.0], [0.0, 1.0]);

        $this->assertSame(0.0, $this->service->score($this->profile(), $this->job()));
    }

    public function test_returns_a_proportional_score_for_partially_similar_vectors(): void
    {
        // cos(45°) ≈ 0.7071 -> ~70.71
        $this->fakeEmbeddings([1.0, 0.0], [1.0, 1.0]);

        $score = $this->service->score($this->profile(), $this->job());

        $this->assertEqualsWithDelta(70.71, $score, 0.01);
    }

    public function test_clamps_a_negative_cosine_similarity_to_0(): void
    {
        $this->fakeEmbeddings([1.0, 0.0], [-1.0, 0.0]);

        $this->assertSame(0.0, $this->service->score($this->profile(), $this->job()));
    }

    public function test_sends_both_texts_with_the_configured_embedding_model(): void
    {
        $this->fakeEmbeddings([1.0], [1.0]);

        $this->service->score($this->profile('My resume text'), $this->job('A job description', ['Go']));

        Http::assertSent(function ($request) {
            $requests = $request->data()['requests'];

            return str_contains($request->url(), 'gemini-embedding-2:batchEmbedContents')
                && $request->hasHeader('x-goog-api-key', 'test-api-key')
                && $requests[0]['content']['parts'][0]['text'] === 'My resume text'
                && str_contains($requests[1]['content']['parts'][0]['text'], 'A job description')
                && str_contains($requests[1]['content']['parts'][0]['text'], 'Go');
        });
    }

    public function test_returns_null_when_gemini_responds_with_an_error_status(): void
    {
        $this->fakeEmbeddings([1.0], [1.0], 503);

        $this->assertNull($this->service->score($this->profile(), $this->job()));
    }

    public function test_returns_null_when_the_request_throws(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out.');
        });

        $this->assertNull($this->service->score($this->profile(), $this->job()));
    }

    public function test_returns_null_when_embeddings_are_missing_from_the_response(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['embeddings' => []])]);

        $this->assertNull($this->service->score($this->profile(), $this->job()));
    }

    public function test_returns_null_when_the_two_vectors_have_mismatched_dimensions(): void
    {
        $this->fakeEmbeddings([1.0, 0.0], [1.0]);

        $this->assertNull($this->service->score($this->profile(), $this->job()));
    }
}
