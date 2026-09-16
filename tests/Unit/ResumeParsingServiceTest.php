<?php

namespace Tests\Unit;

use App\Services\ResumeParsingService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResumeParsingServiceTest extends TestCase
{
    private ResumeParsingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ResumeParsingService;
        config(['services.gemini.api_key' => 'test-api-key', 'services.gemini.model' => 'gemini-2.5-flash']);
    }

    private function fakeGeminiResponse(array $body, int $status = 200): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($body, $status)]);
    }

    private function geminiTextResponse(string $text): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
    }

    public function test_returns_null_when_api_key_is_not_configured(): void
    {
        config(['services.gemini.api_key' => null]);
        Http::fake();

        $this->assertNull($this->service->parse('%PDF-1.4 fake contents'));
        Http::assertNothingSent();
    }

    public function test_returns_structured_data_on_a_successful_response(): void
    {
        $this->fakeGeminiResponse($this->geminiTextResponse(json_encode([
            'skills' => ['PHP', 'Laravel', 'React'],
            'education_level' => 'bachelors',
            'years_experience' => 5,
            'resume_text' => 'Experienced backend engineer...',
        ])));

        $result = $this->service->parse('%PDF-1.4 fake contents');

        $this->assertSame([
            'skills' => ['PHP', 'Laravel', 'React'],
            'education_level' => 'bachelors',
            'years_experience' => 5,
            'resume_text' => 'Experienced backend engineer...',
        ], $result);
    }

    public function test_sends_the_pdf_as_base64_inline_data_with_the_configured_model(): void
    {
        $this->fakeGeminiResponse($this->geminiTextResponse(json_encode([
            'skills' => [], 'years_experience' => 0, 'resume_text' => 'x',
        ])));

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

        $this->assertNull($result['education_level']);
    }

    public function test_ignores_an_education_level_outside_the_known_enum_values(): void
    {
        $this->fakeGeminiResponse($this->geminiTextResponse(json_encode([
            'skills' => [],
            'education_level' => 'doctorate', // not one of our enum's values
            'years_experience' => 0,
            'resume_text' => 'x',
        ])));

        $result = $this->service->parse('contents');

        $this->assertNull($result['education_level']);
    }

    public function test_strips_a_markdown_code_fence_defensively(): void
    {
        $json = json_encode(['skills' => [], 'years_experience' => 1, 'resume_text' => 'x']);
        $this->fakeGeminiResponse($this->geminiTextResponse("```json\n{$json}\n```"));

        $this->assertNotNull($this->service->parse('contents'));
    }

    public function test_returns_null_when_the_response_text_is_not_valid_json(): void
    {
        $this->fakeGeminiResponse($this->geminiTextResponse('not json at all'));

        $this->assertNull($this->service->parse('contents'));
    }

    public function test_returns_null_when_the_response_is_missing_the_expected_shape(): void
    {
        $this->fakeGeminiResponse(['candidates' => []]);

        $this->assertNull($this->service->parse('contents'));
    }

    public function test_returns_null_when_gemini_responds_with_an_error_status(): void
    {
        $this->fakeGeminiResponse(['error' => ['message' => 'rate limited']], 429);

        $this->assertNull($this->service->parse('contents'));
    }

    public function test_returns_null_when_the_request_throws(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out.');
        });

        $this->assertNull($this->service->parse('contents'));
    }
}
