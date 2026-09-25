<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Interview;
use App\Models\User;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\Mail;
use Resend\Client as ResendClient;
use Resend\Transporters\HttpTransporter;
use Resend\ValueObjects\ApiKey;
use Resend\ValueObjects\Transporter\BaseUri;
use Resend\ValueObjects\Transporter\Headers;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * Resend is a ready-to-flip backup for the Brevo transport, selected with
 * MAIL_MAILER=resend. It uses the framework's own ResendTransport (no custom
 * code), and its SDK sends over Guzzle rather than Symfony's HTTP client, so
 * it doesn't hit the curl_multi_exec restriction that needed
 * LaravelBackedHttpClient for Brevo — checked by hand with that function
 * disabled, not testable from inside a running test process. These tests
 * cover the part that is: that the mailer resolves, and that every kind of
 * notification email produces the right request to Resend's API.
 */
class ResendMailTransportTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 're_test_placeholder_key';

    /**
     * Requests the mocked Resend API received, in order.
     *
     * @var list<array{request: PsrRequest, response: PsrResponse|null}>
     */
    private array $resendRequests = [];

    /**
     * Select the resend mailer, replacing only the HTTP layer under the real
     * framework transport with a mock that records requests instead of
     * sending them.
     *
     * @param  list<PsrResponse|\Throwable>  $responses
     */
    private function useResendMailerWithMockedApi(array $responses): void
    {
        config([
            'mail.default' => 'resend',
            'services.resend.key' => self::API_KEY,
            'mail.from.address' => 'hr@example.com',
            'mail.from.name' => 'Job Board',
        ]);

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->resendRequests));

        $client = new ResendClient(new HttpTransporter(
            new GuzzleClient(['handler' => $stack]),
            BaseUri::from('api.resend.com'),
            Headers::withAuthorization(ApiKey::from(self::API_KEY)),
        ));

        Mail::extend('resend', fn () => new ResendTransport($client));
        Mail::purge();
    }

    private function accepted(): PsrResponse
    {
        return new PsrResponse(200, ['Content-Type' => 'application/json'], '{"id":"re_test_message_id"}');
    }

    /**
     * @return array<string, mixed>
     */
    private function sentPayload(int $index = 0): array
    {
        $request = $this->resendRequests[$index]['request'];

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.resend.com/emails', (string) $request->getUri());
        $this->assertSame('Bearer '.self::API_KEY, $request->getHeaderLine('Authorization'));

        return json_decode((string) $request->getBody(), true);
    }

    public function test_the_resend_mailer_resolves_to_the_frameworks_resend_transport(): void
    {
        config(['services.resend.key' => self::API_KEY]);
        Mail::purge();

        $this->assertInstanceOf(ResendTransport::class, Mail::mailer('resend')->getSymfonyTransport());
    }

    public function test_the_active_default_is_not_resend_unless_explicitly_selected(): void
    {
        // This is a backup, not a switch that's been flipped: nothing in the
        // repo's own config selects it, only MAIL_MAILER does.
        $this->assertSame('resend', config('mail.mailers.resend.transport'));
        $this->assertNotSame('resend', config('mail.default'));
    }

    // --- Every kind of notification email, end to end through Resend ---

    public function test_a_rejection_email_carries_the_remarks_through_resend(): void
    {
        $this->useResendMailerWithMockedApi([$this->accepted()]);
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Applied)->create();

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/applications/{$application->id}/status", [
                'status' => 'rejected',
                'rejection_reason' => 'Not enough relevant experience for this role.',
            ])
            ->assertOk();

        $this->assertCount(1, $this->resendRequests);
        $payload = $this->sentPayload();
        $this->assertStringContainsString('hr@example.com', $payload['from']);
        $this->assertSame([$application->candidate->email], $payload['to']);
        $this->assertStringContainsString("Update on your application for {$application->job->title}", $payload['subject']);
        $this->assertStringContainsString('Not enough relevant experience for this role.', $payload['html']);
    }

    public function test_an_interview_scheduled_email_is_sent_through_resend(): void
    {
        $this->useResendMailerWithMockedApi([$this->accepted()]);
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Shortlisted)->create();

        $this->actingAs($hr, 'sanctum')->postJson("/api/applications/{$application->id}/interview", [
            'scheduled_at' => now()->addWeek()->toDateTimeString(),
        ])->assertCreated();

        $interview = Interview::where('application_id', $application->id)->firstOrFail();

        $this->assertCount(1, $this->resendRequests);
        $payload = $this->sentPayload();
        $this->assertSame([$application->candidate->email], $payload['to']);
        $this->assertSame("Interview Scheduled: {$application->job->title}", $payload['subject']);
        // Links into the SPA, not this API — the notification deep-link fix.
        $this->assertStringContainsString(config('app.frontend_url')."/interviews/{$interview->id}", $payload['html']);
    }

    public function test_a_password_reset_email_with_the_frontend_link_is_sent_through_resend(): void
    {
        $this->useResendMailerWithMockedApi([$this->accepted()]);
        $user = User::factory()->create();

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

        $this->assertCount(1, $this->resendRequests);
        $payload = $this->sentPayload();
        $this->assertSame([$user->email], $payload['to']);
        $this->assertStringContainsString(config('app.frontend_url').'/reset-password?token=', $payload['html']);
    }

    // --- Resend errors surface as transport failures, not silent successes ---

    public function test_a_rejected_api_key_is_reported(): void
    {
        $this->useResendMailerWithMockedApi([
            new PsrResponse(401, ['Content-Type' => 'application/json'], '{"statusCode":401,"name":"validation_error","message":"API key is invalid"}'),
        ]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('API key is invalid');

        Mail::raw('Hello', fn ($message) => $message->to('candidate@example.com')->subject('Hello'));
    }

    public function test_an_unreachable_resend_is_reported_as_a_transport_failure(): void
    {
        $this->useResendMailerWithMockedApi([
            new ConnectException('cURL error 6: Could not resolve host: api.resend.com', new PsrRequest('POST', 'https://api.resend.com/emails')),
        ]);

        $this->expectException(TransportException::class);

        Mail::raw('Hello', fn ($message) => $message->to('candidate@example.com')->subject('Hello'));
    }
}
