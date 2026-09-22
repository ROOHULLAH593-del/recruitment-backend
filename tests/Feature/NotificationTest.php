<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\InterviewStatus;
use App\Models\Application;
use App\Models\Interview;
use App\Models\User;
use App\Notifications\ApplicationStatusChanged;
use App\Notifications\InterviewScheduled;
use App\Notifications\OfferSent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_offer_sent_notification_fires_when_status_becomes_offered(): void
    {
        Notification::fake();

        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Interviewed)->create();

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/applications/{$application->id}/status", ['status' => 'offered'])
            ->assertOk();

        Notification::assertSentTo($application->candidate, OfferSent::class);
        Notification::assertNotSentTo($application->candidate, ApplicationStatusChanged::class);
    }

    public function test_application_status_changed_fires_for_a_non_offer_transition(): void
    {
        Notification::fake();

        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Applied)->create();

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/applications/{$application->id}/status", ['status' => 'shortlisted'])
            ->assertOk();

        Notification::assertSentTo(
            $application->candidate,
            ApplicationStatusChanged::class,
            fn ($notification) => $notification->application->id === $application->id
                && $notification->previousStatus === ApplicationStatus::Applied
        );
    }

    public function test_application_status_changed_fires_for_rejected(): void
    {
        Notification::fake();

        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Shortlisted)->create();

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/applications/{$application->id}/status", ['status' => 'rejected'])
            ->assertOk();

        Notification::assertSentTo($application->candidate, ApplicationStatusChanged::class);
    }

    public function test_application_status_changed_fires_for_hired(): void
    {
        Notification::fake();

        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Offered)->create();

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/applications/{$application->id}/status", ['status' => 'hired'])
            ->assertOk();

        Notification::assertSentTo($application->candidate, ApplicationStatusChanged::class);
    }

    public function test_no_notification_fires_when_a_transition_is_invalid(): void
    {
        Notification::fake();

        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Hired)->create();

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/applications/{$application->id}/status", ['status' => 'applied'])
            ->assertStatus(422);

        Notification::assertNothingSent();
    }

    public function test_interview_scheduled_notification_fires_when_an_interview_is_created(): void
    {
        Notification::fake();

        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Shortlisted)->create();

        $this->actingAs($hr, 'sanctum')->postJson("/api/applications/{$application->id}/interview", [
            'scheduled_at' => now()->addWeek()->toDateTimeString(),
        ])->assertCreated();

        Notification::assertSentTo($application->candidate, InterviewScheduled::class);
    }

    public function test_interview_scheduled_notification_fires_again_on_reschedule(): void
    {
        Notification::fake();

        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::InterviewScheduled)->create();
        $interview = Interview::factory()->create(['application_id' => $application->id]);

        $this->actingAs($hr, 'sanctum')->patchJson("/api/interviews/{$interview->id}", [
            'status' => 'rescheduled',
            'scheduled_at' => now()->addMonth()->toDateTimeString(),
        ])->assertOk();

        Notification::assertSentTo($application->candidate, InterviewScheduled::class);
    }

    public function test_application_status_changed_fires_when_interview_marked_completed(): void
    {
        Notification::fake();

        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::InterviewScheduled)->create();
        $interview = Interview::factory()->create(['application_id' => $application->id]);

        $this->actingAs($hr, 'sanctum')->patchJson("/api/interviews/{$interview->id}", [
            'status' => 'completed',
        ])->assertOk();

        Notification::assertSentTo($application->candidate, ApplicationStatusChanged::class);
    }

    public function test_application_status_changed_fires_when_interview_cancelled(): void
    {
        Notification::fake();

        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::InterviewScheduled)->create();
        $interview = Interview::factory()->create(['application_id' => $application->id]);

        $this->actingAs($hr, 'sanctum')->patchJson("/api/interviews/{$interview->id}", [
            'status' => 'cancelled',
        ])->assertOk();

        Notification::assertSentTo($application->candidate, ApplicationStatusChanged::class);
    }

    public function test_no_notification_fires_on_a_notes_only_interview_update(): void
    {
        Notification::fake();

        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::InterviewScheduled)->create();
        $interview = Interview::factory()->create(['application_id' => $application->id]);

        $this->actingAs($hr, 'sanctum')->patchJson("/api/interviews/{$interview->id}", [
            'notes' => 'Just updating notes.',
        ])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_no_duplicate_notification_when_redundantly_re_marking_an_interview_completed(): void
    {
        Notification::fake();

        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Interviewed)->create();
        $interview = Interview::factory()->create([
            'application_id' => $application->id,
            'status' => InterviewStatus::Completed,
        ]);

        $this->actingAs($hr, 'sanctum')->patchJson("/api/interviews/{$interview->id}", [
            'status' => 'completed',
        ])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_notification_includes_the_correct_application_and_job_data(): void
    {
        Notification::fake();

        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Shortlisted)->create();

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/applications/{$application->id}/status", ['status' => 'rejected'])
            ->assertOk();

        Notification::assertSentTo(
            $application->candidate,
            ApplicationStatusChanged::class,
            function (ApplicationStatusChanged $notification) use ($application) {
                $data = $notification->toArray($notification);

                return $data['application_id'] === $application->id
                    && $data['job_id'] === $application->job_id
                    && $data['status'] === 'rejected';
            }
        );
    }

    // --- Email links point at the frontend, not this API ---
    //
    // Notification::action() had been built with url(), which resolves
    // against APP_URL (this Laravel app's own domain) rather than
    // FRONTEND_URL (the React SPA) — on any deployment where those differ,
    // every "View..." link in these emails pointed at a domain with no
    // matching route at all, rendering as a crash/404 for every recipient,
    // regardless of whether they were logged in. Locally the two already
    // differ (:8000 vs :5173), which is what makes these tests meaningful.

    public function test_the_application_status_changed_email_links_to_the_frontend(): void
    {
        Notification::fake();
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Applied)->create();

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/applications/{$application->id}/status", ['status' => 'shortlisted'])
            ->assertOk();

        Notification::assertSentTo(
            $application->candidate,
            ApplicationStatusChanged::class,
            fn (ApplicationStatusChanged $notification) => $notification->toMail($application->candidate)->actionUrl
                === config('app.frontend_url')."/applications/{$application->id}"
        );
    }

    public function test_the_offer_sent_email_links_to_the_frontend(): void
    {
        Notification::fake();
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Interviewed)->create();

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/applications/{$application->id}/status", ['status' => 'offered'])
            ->assertOk();

        Notification::assertSentTo(
            $application->candidate,
            OfferSent::class,
            fn (OfferSent $notification) => $notification->toMail($application->candidate)->actionUrl
                === config('app.frontend_url')."/applications/{$application->id}"
        );
    }

    public function test_the_interview_scheduled_email_links_to_the_frontend(): void
    {
        Notification::fake();
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Shortlisted)->create();

        $this->actingAs($hr, 'sanctum')->postJson("/api/applications/{$application->id}/interview", [
            'scheduled_at' => now()->addWeek()->toDateTimeString(),
        ])->assertCreated();

        $interview = Interview::where('application_id', $application->id)->firstOrFail();

        Notification::assertSentTo(
            $application->candidate,
            InterviewScheduled::class,
            fn (InterviewScheduled $notification) => $notification->toMail($application->candidate)->actionUrl
                === config('app.frontend_url')."/interviews/{$interview->id}"
        );
    }
}
