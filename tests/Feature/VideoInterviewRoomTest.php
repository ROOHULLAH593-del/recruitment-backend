<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\InterviewStatus;
use App\Models\Application;
use App\Models\Interview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoInterviewRoomTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_video_room_is_generated_when_an_interview_is_scheduled(): void
    {
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Shortlisted)->create();

        $response = $this->actingAs($hr, 'sanctum')->postJson("/api/applications/{$application->id}/interview", [
            'scheduled_at' => now()->addWeek()->toDateTimeString(),
        ]);

        $response->assertCreated();
        $room = $response->json('data.video_room');
        $this->assertNotNull($room);
        $this->assertMatchesRegularExpression('/^recruitment-[A-Za-z0-9]{32}$/', $room);

        $interview = Interview::where('application_id', $application->id)->first();
        $this->assertSame($room, $interview->video_room);
    }

    public function test_video_rooms_are_unique_across_interviews(): void
    {
        $hr = User::factory()->hr()->create();

        $first = Application::factory()->status(ApplicationStatus::Shortlisted)->create();
        $second = Application::factory()->status(ApplicationStatus::Shortlisted)->create();

        $roomOne = $this->actingAs($hr, 'sanctum')
            ->postJson("/api/applications/{$first->id}/interview", ['scheduled_at' => now()->addWeek()->toDateTimeString()])
            ->json('data.video_room');
        $roomTwo = $this->actingAs($hr, 'sanctum')
            ->postJson("/api/applications/{$second->id}/interview", ['scheduled_at' => now()->addWeeks(2)->toDateTimeString()])
            ->json('data.video_room');

        $this->assertNotSame($roomOne, $roomTwo);
    }

    public function test_a_pre_existing_interview_without_a_room_gets_one_lazily_on_first_access(): void
    {
        $hr = User::factory()->hr()->create();
        $interview = Interview::factory()->create(['video_room' => null]);
        $this->assertNull($interview->video_room);

        $response = $this->actingAs($hr, 'sanctum')->getJson('/api/interviews');

        $room = collect($response->json('data'))->firstWhere('id', $interview->id)['video_room'];
        $this->assertNotNull($room);
        $this->assertMatchesRegularExpression('/^recruitment-[A-Za-z0-9]{32}$/', $room);
        // Actually persisted, not just returned once — a bulk backfill was
        // deliberately avoided, but the lazy generation still has to stick.
        $this->assertSame($room, $interview->fresh()->video_room);
    }

    public function test_the_lazily_generated_room_is_not_regenerated_on_a_later_access(): void
    {
        $hr = User::factory()->hr()->create();
        $interview = Interview::factory()->create(['video_room' => null]);

        $firstRoom = $this->actingAs($hr, 'sanctum')->getJson('/api/interviews')->json('data.0.video_room');
        $secondRoom = $this->actingAs($hr, 'sanctum')->getJson('/api/interviews')->json('data.0.video_room');

        $this->assertSame($firstRoom, $secondRoom);
    }

    // --- Authorization matrix ---

    public function test_the_owning_candidate_can_see_the_video_room(): void
    {
        $candidate = User::factory()->create();
        $application = Application::factory()->create(['candidate_id' => $candidate->id]);
        $interview = Interview::factory()->create(['application_id' => $application->id]);

        $this->actingAs($candidate, 'sanctum')
            ->getJson("/api/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.interview.video_room', $interview->fresh()->video_room);
    }

    public function test_another_candidate_cannot_see_the_video_room(): void
    {
        $otherCandidate = User::factory()->create();
        $candidate = User::factory()->create();
        $application = Application::factory()->create(['candidate_id' => $candidate->id]);
        $interview = Interview::factory()->create(['application_id' => $application->id]);

        $response = $this->actingAs($otherCandidate, 'sanctum')->getJson("/api/applications/{$application->id}");

        // Blocked outright by ApplicationPolicy — but assert the room value
        // itself never appears in the response either, as a defense-in-
        // depth check independent of that outer gate.
        $response->assertStatus(403);
        $this->assertStringNotContainsString($interview->videoRoom(), $response->getContent());
    }

    public function test_hr_can_see_the_video_room(): void
    {
        $hr = User::factory()->hr()->create();
        $interview = Interview::factory()->create();

        $this->actingAs($hr, 'sanctum')
            ->getJson('/api/interviews')
            ->assertOk()
            ->assertJsonPath('data.0.video_room', $interview->fresh()->video_room);
    }

    public function test_assistant_hr_can_see_the_video_room(): void
    {
        $assistantHr = User::factory()->assistantHr()->create();
        $interview = Interview::factory()->create();

        $this->actingAs($assistantHr, 'sanctum')
            ->getJson('/api/interviews')
            ->assertOk()
            ->assertJsonPath('data.0.video_room', $interview->fresh()->video_room);
    }

    public function test_admin_can_see_the_video_room(): void
    {
        $admin = User::factory()->admin()->create();
        $interview = Interview::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/interviews')
            ->assertOk()
            ->assertJsonPath('data.0.video_room', $interview->fresh()->video_room);
    }

    public function test_guest_cannot_see_the_video_room(): void
    {
        Interview::factory()->create();

        $this->getJson('/api/interviews')->assertStatus(401);
    }

    public function test_a_cancelled_interviews_room_is_not_exposed_to_the_owning_candidate(): void
    {
        $candidate = User::factory()->create();
        $application = Application::factory()->create(['candidate_id' => $candidate->id]);
        $interview = Interview::factory()->create([
            'application_id' => $application->id,
            'status' => InterviewStatus::Cancelled,
        ]);

        $response = $this->actingAs($candidate, 'sanctum')->getJson("/api/applications/{$application->id}");

        $response->assertOk()->assertJsonMissingPath('data.interview.video_room');
    }

    public function test_a_cancelled_interviews_room_is_not_exposed_to_hr(): void
    {
        $hr = User::factory()->hr()->create();
        $interview = Interview::factory()->create(['status' => InterviewStatus::Cancelled]);

        $this->actingAs($hr, 'sanctum')
            ->getJson('/api/interviews')
            ->assertOk()
            ->assertJsonMissingPath('data.0.video_room');

        // Cancellation also shouldn't be the trigger that lazily backfills
        // a room that was never generated — there's nothing to join, so
        // nothing worth persisting either.
        $this->assertNull($interview->fresh()->video_room);
    }
}
