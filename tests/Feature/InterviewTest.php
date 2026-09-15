<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\InterviewStatus;
use App\Models\Application;
use App\Models\Interview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InterviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_can_schedule_an_interview_for_a_shortlisted_application(): void
    {
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Shortlisted)->create();

        $response = $this->actingAs($hr, 'sanctum')->postJson("/api/applications/{$application->id}/interview", [
            'scheduled_at' => now()->addWeek()->toDateTimeString(),
            'notes' => 'Initial screen.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.interviewer.id', $hr->id);

        $this->assertSame('interview_scheduled', $application->fresh()->status->value);
    }

    public function test_scheduling_an_interview_for_an_applied_status_application_is_rejected(): void
    {
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Applied)->create();

        $this->actingAs($hr, 'sanctum')->postJson("/api/applications/{$application->id}/interview", [
            'scheduled_at' => now()->addWeek()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrors('application');

        $this->assertNull($application->fresh()->interview);
    }

    public function test_scheduling_a_second_interview_for_the_same_application_is_rejected(): void
    {
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::InterviewScheduled)->create();
        Interview::factory()->create(['application_id' => $application->id]);

        $this->actingAs($hr, 'sanctum')->postJson("/api/applications/{$application->id}/interview", [
            'scheduled_at' => now()->addWeek()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrors('application');

        $this->assertSame(1, Interview::where('application_id', $application->id)->count());
    }

    public function test_double_booking_the_same_interviewer_at_the_same_time_is_rejected(): void
    {
        $hr = User::factory()->hr()->create();
        $time = now()->addWeek();

        $existingApplication = Application::factory()->status(ApplicationStatus::Shortlisted)->create();
        Interview::factory()->create([
            'application_id' => $existingApplication->id,
            'interviewer_id' => $hr->id,
            'scheduled_at' => $time,
            'status' => InterviewStatus::Scheduled,
        ]);

        $newApplication = Application::factory()->status(ApplicationStatus::Shortlisted)->create();

        $response = $this->actingAs($hr, 'sanctum')->postJson("/api/applications/{$newApplication->id}/interview", [
            'scheduled_at' => $time->toDateTimeString(),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('scheduled_at');
    }

    public function test_double_booking_check_ignores_cancelled_interviews(): void
    {
        $hr = User::factory()->hr()->create();
        $time = now()->addWeek();

        $cancelledApplication = Application::factory()->status(ApplicationStatus::Shortlisted)->create();
        Interview::factory()->create([
            'application_id' => $cancelledApplication->id,
            'interviewer_id' => $hr->id,
            'scheduled_at' => $time,
            'status' => InterviewStatus::Cancelled,
        ]);

        $newApplication = Application::factory()->status(ApplicationStatus::Shortlisted)->create();

        $this->actingAs($hr, 'sanctum')->postJson("/api/applications/{$newApplication->id}/interview", [
            'scheduled_at' => $time->toDateTimeString(),
        ])->assertCreated();
    }

    public function test_a_different_interviewer_can_book_the_same_time_slot(): void
    {
        $hrOne = User::factory()->hr()->create();
        $hrTwo = User::factory()->hr()->create();
        $time = now()->addWeek();

        $existingApplication = Application::factory()->status(ApplicationStatus::Shortlisted)->create();
        Interview::factory()->create([
            'application_id' => $existingApplication->id,
            'interviewer_id' => $hrOne->id,
            'scheduled_at' => $time,
        ]);

        $newApplication = Application::factory()->status(ApplicationStatus::Shortlisted)->create();

        $this->actingAs($hrTwo, 'sanctum')->postJson("/api/applications/{$newApplication->id}/interview", [
            'scheduled_at' => $time->toDateTimeString(),
        ])->assertCreated();
    }

    public function test_scheduling_in_the_past_is_rejected(): void
    {
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Shortlisted)->create();

        $this->actingAs($hr, 'sanctum')->postJson("/api/applications/{$application->id}/interview", [
            'scheduled_at' => now()->subDay()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrors('scheduled_at');
    }

    public function test_rescheduling_to_a_past_date_is_rejected(): void
    {
        $hr = User::factory()->hr()->create();
        $interview = Interview::factory()->create(['interviewer_id' => $hr->id]);

        $this->actingAs($hr, 'sanctum')->patchJson("/api/interviews/{$interview->id}", [
            'status' => 'rescheduled',
            'scheduled_at' => now()->subDay()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrors('scheduled_at');
    }

    public function test_rescheduling_to_the_time_it_already_occupies_is_not_a_self_conflict(): void
    {
        $hr = User::factory()->hr()->create();
        $time = now()->addWeek();
        $interview = Interview::factory()->create(['interviewer_id' => $hr->id, 'scheduled_at' => $time]);

        $this->actingAs($hr, 'sanctum')->patchJson("/api/interviews/{$interview->id}", [
            'status' => 'rescheduled',
            'scheduled_at' => $time->toDateTimeString(),
        ])->assertOk();
    }

    public function test_marking_an_interview_completed_bumps_application_to_interviewed(): void
    {
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::InterviewScheduled)->create();
        $interview = Interview::factory()->create(['application_id' => $application->id]);

        $this->actingAs($hr, 'sanctum')->patchJson("/api/interviews/{$interview->id}", [
            'status' => 'completed',
        ])->assertOk();

        $this->assertSame('interviewed', $application->fresh()->status->value);
        $this->assertSame('completed', $interview->fresh()->status->value);
    }

    public function test_cancelling_an_interview_reverts_application_to_shortlisted(): void
    {
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::InterviewScheduled)->create();
        $interview = Interview::factory()->create(['application_id' => $application->id]);

        $this->actingAs($hr, 'sanctum')->patchJson("/api/interviews/{$interview->id}", [
            'status' => 'cancelled',
        ])->assertOk();

        $this->assertSame('shortlisted', $application->fresh()->status->value);
    }

    public function test_rescheduling_an_interview_does_not_change_application_status(): void
    {
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::InterviewScheduled)->create();
        $interview = Interview::factory()->create(['application_id' => $application->id]);

        $this->actingAs($hr, 'sanctum')->patchJson("/api/interviews/{$interview->id}", [
            'status' => 'rescheduled',
            'scheduled_at' => now()->addMonth()->toDateTimeString(),
        ])->assertOk();

        $this->assertSame('interview_scheduled', $application->fresh()->status->value);
    }

    public function test_updating_notes_only_does_not_change_application_status(): void
    {
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::InterviewScheduled)->create();
        $interview = Interview::factory()->create(['application_id' => $application->id]);

        $this->actingAs($hr, 'sanctum')->patchJson("/api/interviews/{$interview->id}", [
            'notes' => 'Just a note update.',
        ])->assertOk();

        $this->assertSame('interview_scheduled', $application->fresh()->status->value);
    }

    public function test_redundant_status_update_does_not_change_an_already_matching_application_status(): void
    {
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Interviewed)->create();
        $interview = Interview::factory()->create([
            'application_id' => $application->id,
            'status' => InterviewStatus::Completed,
        ]);

        $this->actingAs($hr, 'sanctum')->patchJson("/api/interviews/{$interview->id}", [
            'status' => 'completed',
        ])->assertOk();

        $this->assertSame('interviewed', $application->fresh()->status->value);
    }

    public function test_candidate_cannot_schedule_an_interview(): void
    {
        $candidate = User::factory()->create();
        $application = Application::factory()->status(ApplicationStatus::Shortlisted)->create(['candidate_id' => $candidate->id]);

        $this->actingAs($candidate, 'sanctum')->postJson("/api/applications/{$application->id}/interview", [
            'scheduled_at' => now()->addWeek()->toDateTimeString(),
        ])->assertStatus(403);
    }

    public function test_candidate_cannot_update_an_interview(): void
    {
        $candidate = User::factory()->create();
        $application = Application::factory()->create(['candidate_id' => $candidate->id]);
        $interview = Interview::factory()->create(['application_id' => $application->id]);

        $this->actingAs($candidate, 'sanctum')
            ->patchJson("/api/interviews/{$interview->id}", ['status' => 'cancelled'])
            ->assertStatus(403);
    }

    public function test_candidate_index_only_returns_their_own_interviews(): void
    {
        $candidate = User::factory()->create();
        $ownApplication = Application::factory()->create(['candidate_id' => $candidate->id]);
        Interview::factory()->create(['application_id' => $ownApplication->id]);

        Interview::factory()->count(2)->create();

        $response = $this->actingAs($candidate, 'sanctum')->getJson('/api/interviews');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_hr_index_returns_all_interviews(): void
    {
        $hr = User::factory()->hr()->create();
        Interview::factory()->count(3)->create();

        $response = $this->actingAs($hr, 'sanctum')->getJson('/api/interviews');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }
}
