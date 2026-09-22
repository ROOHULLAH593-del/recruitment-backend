<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Interview;
use App\Models\User;
use App\Services\LaravelBackedHttpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use ReflectionProperty;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\JsonException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Transport\AbstractHttpTransport;
use Tests\TestCase;

/**
 * Some shared hosts (InfinityFree) disable curl_multi_exec(), which Symfony's
 * default HTTP client needs, so the Brevo transport is pointed at Laravel's
 * HTTP client there instead. These tests prove that path delivers every
 * notification, reports Brevo errors properly, and is opt-in — the default
 * client is untouched everywhere else.
 */
class LaravelBackedHttpClientTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'xkeysib-test-key';

    private function useBrevoMailer(string $httpClient): void
    {
        config([
            'mail.default' => 'brevo',
            'services.brevo.key' => self::API_KEY,
            'services.brevo.http_client' => $httpClient,
            'mail.from.address' => 'hr@example.com',
            'mail.from.name' => 'Job Board',
        ]);

        Mail::purge();
    }

    private function fakeBrevoAccepting(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => '<test@smtp-relay.brevo.com>'], 201)]);
    }

    private function httpClientUnderBrevoTransport(): object
    {
        $transport = Mail::mailer('brevo')->getSymfonyTransport();

        return (new ReflectionProperty(AbstractHttpTransport::class, 'client'))->getValue($transport);
    }

    private function sendPlainMail(): void
    {
        Mail::raw('Hello', fn ($message) => $message->to('candidate@example.com')->subject('Hello'));
    }

    // --- Which client is used ---

    public function test_the_laravel_client_is_used_when_selected(): void
    {
        $this->useBrevoMailer('laravel');

        $this->assertInstanceOf(LaravelBackedHttpClient::class, $this->httpClientUnderBrevoTransport());
    }

    public function test_symfonys_own_client_is_used_when_selected(): void
    {
        $this->useBrevoMailer('symfony');

        $this->assertInstanceOf(CurlHttpClient::class, $this->httpClientUnderBrevoTransport());
    }

    public function test_symfonys_own_client_stays_the_default_wherever_curl_multi_exec_exists(): void
    {
        // Local development, Render and any normal host: nothing changes.
        $this->assertTrue(function_exists('curl_multi_exec'), 'This test assumes a normal PHP with curl_multi_exec.');

        $this->useBrevoMailer('auto');

        $this->assertNotInstanceOf(LaravelBackedHttpClient::class, $this->httpClientUnderBrevoTransport());
    }

    // --- Every notification, through the Laravel client ---

    public function test_every_notification_email_is_delivered_through_the_laravel_client(): void
    {
        $this->useBrevoMailer('laravel');
        $this->fakeBrevoAccepting();
        $hr = User::factory()->hr()->create();

        $toShortlist = Application::factory()->status(ApplicationStatus::Applied)->create();
        $toReject = Application::factory()->status(ApplicationStatus::Applied)->create();
        $toInterview = Application::factory()->status(ApplicationStatus::Shortlisted)->create();
        $toOffer = Application::factory()->status(ApplicationStatus::Interviewed)->create();
        $rescheduled = Application::factory()->status(ApplicationStatus::InterviewScheduled)->create();
        $interview = Interview::factory()->create(['application_id' => $rescheduled->id]);
        $resetUser = User::factory()->create();

        $this->actingAs($hr, 'sanctum');
        $this->patchJson("/api/applications/{$toShortlist->id}/status", ['status' => 'shortlisted'])->assertOk();
        $this->patchJson("/api/applications/{$toReject->id}/status", ['status' => 'rejected', 'rejection_reason' => 'Not enough relevant experience for this role.'])->assertOk();
        $this->postJson("/api/applications/{$toInterview->id}/interview", ['scheduled_at' => now()->addWeek()->toDateTimeString()])->assertCreated();
        $this->patchJson("/api/interviews/{$interview->id}", ['status' => 'rescheduled', 'scheduled_at' => now()->addMonth()->toDateTimeString()])->assertOk();
        $this->patchJson("/api/applications/{$toOffer->id}/status", ['status' => 'offered'])->assertOk();
        $this->postJson('/api/forgot-password', ['email' => $resetUser->email])->assertOk();

        $sent = Http::recorded()->map(fn (array $pair): Request => $pair[0]);
        $this->assertCount(6, $sent, 'Expected one Brevo API call per notification flow.');

        foreach ($sent as $request) {
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://api.brevo.com/v3/smtp/email', $request->url());
            $this->assertSame([self::API_KEY], $request->header('api-key'));
            $this->assertSame('hr@example.com', $request->data()['sender']['email']);
        }

        $this->assertEqualsCanonicalizing(
            [
                $toShortlist->candidate->email,
                $toReject->candidate->email,
                $toInterview->candidate->email,
                $rescheduled->candidate->email,
                $toOffer->candidate->email,
                $resetUser->email,
            ],
            $sent->map(fn (Request $request): string => $request->data()['to'][0]['email'])->all(),
        );

        $subjects = $sent->map(fn (Request $request): string => $request->data()['subject'])->implode(' | ');
        $this->assertStringContainsString('Interview Scheduled:', $subjects);
        $this->assertStringContainsString('Interview Rescheduled:', $subjects);
        $this->assertStringContainsString('Job Offer:', $subjects);
        $this->assertStringContainsString('Reset', $subjects);

        $bodies = $sent->map(fn (Request $request): string => $request->data()['htmlContent'])->implode(' ');
        $this->assertStringContainsString('Not enough relevant experience for this role.', $bodies);
        $this->assertStringContainsString(config('app.frontend_url').'/reset-password?token=', $bodies);
    }

    // --- Brevo errors surface the way Symfony's own client surfaces them ---

    public function test_a_rejected_api_key_is_reported_with_brevos_message_and_status(): void
    {
        $this->useBrevoMailer('laravel');
        Http::fake(['api.brevo.com/*' => Http::response(['message' => 'Key not found', 'code' => 'unauthorized'], 401)]);

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('Unable to send an email: Key not found (code 401).');

        $this->sendPlainMail();
    }

    public function test_an_unreachable_brevo_is_reported_as_a_transport_failure(): void
    {
        $this->useBrevoMailer('laravel');
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: api.brevo.com'));

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('Could not reach the remote Brevo server.');

        $this->sendPlainMail();
    }

    // --- The bridge itself ---

    public function test_the_bridge_sends_json_bodies_and_headers_in_both_symfony_header_styles(): void
    {
        Http::fake(['example.test/*' => Http::response('{"ok":true}', 200, ['Content-Type' => 'application/json'])]);

        $response = (new LaravelBackedHttpClient)->request('POST', 'https://example.test/things', [
            'json' => ['name' => 'value'],
            'headers' => ['api-key' => 'secret', 'X-Trace: abc'],
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['ok' => true], $response->toArray());

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://example.test/things'
            && $request->method() === 'POST'
            && $request['name'] === 'value'
            && $request->header('api-key') === ['secret']
            && $request->header('X-Trace') === ['abc']);
    }

    public function test_the_bridge_exposes_symfony_shaped_response_info_and_lowercase_headers(): void
    {
        Http::fake(['example.test/*' => Http::response('hi', 201, ['X-Custom' => 'yes'])]);

        $response = (new LaravelBackedHttpClient)->request('GET', 'https://example.test/x');

        $this->assertSame('yes', $response->getHeaders()['x-custom'][0]);
        $this->assertSame(201, $response->getInfo('http_code'));
        $this->assertSame('https://example.test/x', $response->getInfo('url'));
        $this->assertContains('X-Custom: yes', $response->getInfo('response_headers'));
        $this->assertNull($response->getInfo('error'));
    }

    public function test_http_errors_throw_only_when_asked_to(): void
    {
        Http::fake([
            'example.test/bad' => Http::response('nope', 404),
            'example.test/boom' => Http::response('down', 503),
        ]);
        $client = new LaravelBackedHttpClient;

        $notFound = $client->request('GET', 'https://example.test/bad');
        $this->assertSame('nope', $notFound->getContent(false));

        try {
            $notFound->getContent();
            $this->fail('A 404 should throw when errors are requested.');
        } catch (ClientException $exception) {
            $this->assertSame(404, $exception->getResponse()->getStatusCode());
        }

        $this->expectException(ServerException::class);
        $client->request('GET', 'https://example.test/boom')->getContent();
    }

    public function test_a_network_failure_is_thrown_lazily_when_the_response_is_inspected(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        $response = (new LaravelBackedHttpClient)->request('GET', 'https://example.test/slow');

        $this->assertSame('cURL error 28: timed out', $response->getInfo('error'));
        $this->expectException(TransportException::class);
        $response->getStatusCode();
    }

    public function test_a_non_json_body_fails_to_decode_with_symfonys_decoding_exception(): void
    {
        Http::fake(['example.test/*' => Http::response('<html>not json</html>', 200)]);

        $this->expectException(JsonException::class);

        (new LaravelBackedHttpClient)->request('GET', 'https://example.test/html')->toArray();
    }

    public function test_default_options_can_be_layered_with_with_options(): void
    {
        Http::fake(['example.test/*' => Http::response('{}', 200)]);

        (new LaravelBackedHttpClient)
            ->withOptions(['headers' => ['X-App' => 'recruitment']])
            ->request('GET', 'https://example.test/x');

        Http::assertSent(fn (Request $request): bool => $request->header('X-App') === ['recruitment']);
    }

    public function test_streaming_is_explicitly_unsupported(): void
    {
        $client = new LaravelBackedHttpClient;
        $response = $client->request('GET', 'https://example.test/x');

        $this->expectException(\LogicException::class);

        $client->stream($response);
    }
}
