<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Interview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoApiTransport;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

/**
 * Proves every notification email still goes out unchanged when production
 * selects MAIL_MAILER=brevo. The HTTP layer is mocked (no real Brevo call),
 * but everything above it — the notifications, the Laravel mailer, and the
 * real Symfony Brevo API transport that builds the request — is the real thing.
 */
class BrevoMailTransportTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'xkeysib-test-key';

    /**
     * Requests the mocked Brevo API received, in order.
     *
     * @var list<array{method: string, url: string, headers: array<int, string>, payload: array<string, mixed>}>
     */
    private array $brevoRequests = [];

    /**
     * Select the brevo mailer, swapping only the HTTP client under the real
     * Symfony transport for one that records requests instead of sending them.
     */
    private function useBrevoMailerWithMockedApi(): void
    {
        config([
            'mail.default' => 'brevo',
            'services.brevo.key' => self::API_KEY,
            'mail.from.address' => 'hr@example.com',
            'mail.from.name' => 'Job Board',
        ]);

        $client = new MockHttpClient(function (string $method, string $url, array $options) {
            $this->brevoRequests[] = [
                'method' => $method,
                'url' => $url,
                'headers' => $options['headers'],
                'payload' => json_decode($options['body'], true),
            ];

            return new MockResponse('{"messageId":"<test@smtp-relay.brevo.com>"}', ['http_code' => 201]);
        });

        Mail::extend('brevo', fn () => (new BrevoTransportFactory(null, $client))
            ->create(new Dsn('brevo+api', 'default', self::API_KEY)));
        Mail::purge();
    }

    /**
     * The single request expected to have been made, with the shared
     * request-shape assertions applied.
     *
     * @return array<string, mixed>
     */
    private function sentPayload(): array
    {
        $this->assertCount(1, $this->brevoRequests, 'Expected exactly one Brevo API call.');
        $request = $this->brevoRequests[0];

        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://api.brevo.com/v3/smtp/email', $request['url']);
        $this->assertContains('api-key: '.self::API_KEY, $request['headers']);
        $this->assertSame('hr@example.com', $request['payload']['sender']['email']);
        $this->assertSame('Job Board', $request['payload']['sender']['name']);

        return $request['payload'];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function bodyOf(array $payload): string
    {
        return ($payload['htmlContent'] ?? '').' '.($payload['textContent'] ?? '');
    }

    // --- Transport selection ---

    public function test_the_brevo_mailer_resolves_to_the_symfony_brevo_api_transport(): void
    {
        config(['services.brevo.key' => self::API_KEY]);
        Mail::purge();

        $this->assertInstanceOf(BrevoApiTransport::class, Mail::mailer('brevo')->getSymfonyTransport());
    }

    public function test_the_brevo_mailer_fails_clearly_without_an_api_key(): void
    {
        config(['services.brevo.key' => null]);
        Mail::purge();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('BREVO_API_KEY');

        Mail::mailer('brevo');
    }

    public function test_the_smtp_mailer_used_locally_is_unaffected(): void
    {
        Mail::purge();

        $this->assertInstanceOf(EsmtpTransport::class, Mail::mailer('smtp')->getSymfonyTransport());
    }

    // --- Every notification, end to end through the Brevo transport ---

    public function test_an_application_status_change_email_is_sent_through_brevo(): void
    {
        $this->useBrevoMailerWithMockedApi();
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Applied)->create();

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/applications/{$application->id}/status", ['status' => 'shortlisted'])
            ->assertOk();

        $payload = $this->sentPayload();
        $this->assertSame($application->candidate->email, $payload['to'][0]['email']);
        $this->assertStringContainsString("Update on your application for {$application->job->title}", $payload['subject']);
        $this->assertNotSame('', trim($this->bodyOf($payload)));
    }

    public function test_a_rejection_email_carries_the_rejection_remarks_through_brevo(): void
    {
        $this->useBrevoMailerWithMockedApi();
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Applied)->create();

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/applications/{$application->id}/status", [
                'status' => 'rejected',
                'rejection_reason' => 'Not enough relevant experience for this role.',
            ])
            ->assertOk();

        $payload = $this->sentPayload();
        $this->assertSame($application->candidate->email, $payload['to'][0]['email']);
        $this->assertStringContainsString('Not enough relevant experience for this role.', $this->bodyOf($payload));
    }

    public function test_an_offer_email_is_sent_through_brevo(): void
    {
        $this->useBrevoMailerWithMockedApi();
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Interviewed)->create();

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/applications/{$application->id}/status", ['status' => 'offered'])
            ->assertOk();

        $payload = $this->sentPayload();
        $this->assertSame($application->candidate->email, $payload['to'][0]['email']);
        $this->assertSame("Job Offer: {$application->job->title}", $payload['subject']);
    }

    public function test_an_interview_scheduled_email_is_sent_through_brevo(): void
    {
        $this->useBrevoMailerWithMockedApi();
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Shortlisted)->create();

        $this->actingAs($hr, 'sanctum')->postJson("/api/applications/{$application->id}/interview", [
            'scheduled_at' => now()->addWeek()->toDateTimeString(),
        ])->assertCreated();

        $payload = $this->sentPayload();
        $this->assertSame($application->candidate->email, $payload['to'][0]['email']);
        $this->assertSame("Interview Scheduled: {$application->job->title}", $payload['subject']);
    }

    public function test_an_interview_rescheduled_email_is_sent_through_brevo(): void
    {
        $this->useBrevoMailerWithMockedApi();
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::InterviewScheduled)->create();
        $interview = Interview::factory()->create(['application_id' => $application->id]);

        $this->actingAs($hr, 'sanctum')->patchJson("/api/interviews/{$interview->id}", [
            'status' => 'rescheduled',
            'scheduled_at' => now()->addMonth()->toDateTimeString(),
        ])->assertOk();

        $payload = $this->sentPayload();
        $this->assertSame($application->candidate->email, $payload['to'][0]['email']);
        $this->assertSame("Interview Rescheduled: {$application->job->title}", $payload['subject']);
    }

    public function test_a_password_reset_email_with_the_frontend_link_is_sent_through_brevo(): void
    {
        $this->useBrevoMailerWithMockedApi();
        $user = User::factory()->create();

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

        $payload = $this->sentPayload();
        $this->assertSame($user->email, $payload['to'][0]['email']);
        $body = $this->bodyOf($payload);
        $this->assertStringContainsString(config('app.frontend_url').'/reset-password?token=', $body);
        $this->assertStringContainsString('email='.urlencode($user->email), $body);
    }
}
