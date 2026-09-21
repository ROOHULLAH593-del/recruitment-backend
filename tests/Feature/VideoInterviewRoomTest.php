<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\InterviewStatus;
use App\Models\Application;
use App\Models\Interview;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoInterviewRoomTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Decodes and signature-verifies a JWT issued by JaasService, using the
     * public key derived from the same private key the app signs with —
     * proving the token is genuinely RS256-signed, not just present.
     *
     * @return array<string, mixed>
     */
    private function decodeJaasToken(string $jwt): array
    {
        $privateKey = openssl_pkey_get_private(file_get_contents(config('services.jaas.private_key_path')));
        $publicKeyPem = openssl_pkey_get_details($privateKey)['key'];

        $decoded = JWT::decode($jwt, new Key($publicKeyPem, 'RS256'));

        return json_decode(json_encode($decoded), true);
    }

    public function test_a_video_room_is_generated_when_an_interview_is_scheduled(): void
    {
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Shortlisted)->create();

        $response = $this->actingAs($hr, 'sanctum')->postJson("/api/applications/{$application->id}/interview", [
            'scheduled_at' => now()->addWeek()->toDateTimeString(),
        ]);

        $response->assertCreated();
        $room = $response->json('data.video_call.room');
        $this->assertNotNull($room);
        // Lowercase: JaaS normalizes the room portion for the actual XMPP
        // conference address, so the JWT/roomName must match that exactly
        // even though the stored identifier itself is mixed-case.
        $this->assertMatchesRegularExpression(
            '#^'.preg_quote(config('services.jaas.app_id'), '#').'/recruitment-[a-z0-9]{32}$#',
            $room,
        );

        $interview = Interview::where('application_id', $application->id)->first();
        $this->assertSame(config('services.jaas.app_id').'/'.strtolower($interview->video_room), $room);
    }

    public function test_video_rooms_are_unique_across_interviews(): void
    {
        $hr = User::factory()->hr()->create();

        $first = Application::factory()->status(ApplicationStatus::Shortlisted)->create();
        $second = Application::factory()->status(ApplicationStatus::Shortlisted)->create();

        $roomOne = $this->actingAs($hr, 'sanctum')
            ->postJson("/api/applications/{$first->id}/interview", ['scheduled_at' => now()->addWeek()->toDateTimeString()])
            ->json('data.video_call.room');
        $roomTwo = $this->actingAs($hr, 'sanctum')
            ->postJson("/api/applications/{$second->id}/interview", ['scheduled_at' => now()->addWeeks(2)->toDateTimeString()])
            ->json('data.video_call.room');

        $this->assertNotSame($roomOne, $roomTwo);
    }

    public function test_a_pre_existing_interview_without_a_room_gets_one_lazily_on_first_access(): void
    {
        $hr = User::factory()->hr()->create();
        $interview = Interview::factory()->create(['video_room' => null]);
        $this->assertNull($interview->video_room);

        $response = $this->actingAs($hr, 'sanctum')->getJson('/api/interviews');

        $room = collect($response->json('data'))->firstWhere('id', $interview->id)['video_call']['room'];
        $this->assertNotNull($room);
        $this->assertStringEndsWith(strtolower($interview->fresh()->video_room), $room);
        // Actually persisted, not just returned once — a bulk backfill was
        // deliberately avoided, but the lazy generation still has to stick.
        $this->assertNotNull($interview->fresh()->video_room);
    }

    public function test_the_lazily_generated_room_is_not_regenerated_on_a_later_access(): void
    {
        $hr = User::factory()->hr()->create();
        $interview = Interview::factory()->create(['video_room' => null]);

        $firstRoom = $this->actingAs($hr, 'sanctum')->getJson('/api/interviews')->json('data.0.video_call.room');
        $secondRoom = $this->actingAs($hr, 'sanctum')->getJson('/api/interviews')->json('data.0.video_call.room');

        $this->assertSame($firstRoom, $secondRoom);
    }

    // --- JWT contents ---

    public function test_the_jwt_is_validly_signed_and_scoped_to_the_specific_room(): void
    {
        $hr = User::factory()->hr()->create();
        $interview = Interview::factory()->create();

        $response = $this->actingAs($hr, 'sanctum')->getJson('/api/interviews');
        $videoCall = $response->json('data.0.video_call');

        $claims = $this->decodeJaasToken($videoCall['jwt']);

        $this->assertSame('jitsi', $claims['aud']);
        $this->assertSame('chat', $claims['iss']);
        $this->assertSame(config('services.jaas.app_id'), $claims['sub']);
        // The JWT's `room` is deliberately the bare identifier, not
        // video_call.room's App-ID-prefixed form — JaaS strips that prefix
        // from the External API's roomName before comparing the two, so
        // the claim itself must already be bare (confirmed by testing
        // both forms directly against JaaS: the prefixed form fails every
        // join with "Room and token mismatched").
        $this->assertSame(config('services.jaas.app_id').'/'.$claims['room'], $videoCall['room']);
        // Never a wildcard — a leaked token must not double as access to
        // every other interview's room too.
        $this->assertNotSame('*', $claims['room']);
        $this->assertGreaterThan(time(), $claims['exp']);
        $this->assertLessThanOrEqual(time(), $claims['nbf']);
    }

    public function test_the_jwt_identifies_the_real_requesting_user(): void
    {
        $hr = User::factory()->hr()->create(['name' => 'Pat Rivera', 'email' => 'pat@example.com']);
        $interview = Interview::factory()->create();

        $jwt = $this->actingAs($hr, 'sanctum')->getJson('/api/interviews')->json('data.0.video_call.jwt');
        $claims = $this->decodeJaasToken($jwt);

        $this->assertSame('Pat Rivera', $claims['context']['user']['name']);
        $this->assertSame('pat@example.com', $claims['context']['user']['email']);
    }

    public function test_staff_get_a_moderator_jwt(): void
    {
        foreach (['hr', 'assistantHr', 'admin'] as $factoryState) {
            $staff = User::factory()->{$factoryState}()->create();
            $interview = Interview::factory()->create();

            $jwt = $this->actingAs($staff, 'sanctum')->getJson('/api/interviews')->json('data.0.video_call.jwt');
            $claims = $this->decodeJaasToken($jwt);

            // JaaS expects the literal string "true"/"false", not a JSON boolean.
            $this->assertSame('true', $claims['context']['user']['moderator'], "Failed for role: {$factoryState}");
        }
    }

    public function test_the_owning_candidate_gets_a_non_moderator_jwt(): void
    {
        $candidate = User::factory()->create();
        $application = Application::factory()->create(['candidate_id' => $candidate->id]);
        Interview::factory()->create(['application_id' => $application->id]);

        $jwt = $this->actingAs($candidate, 'sanctum')
            ->getJson("/api/applications/{$application->id}")
            ->json('data.interview.video_call.jwt');
        $claims = $this->decodeJaasToken($jwt);

        $this->assertSame('false', $claims['context']['user']['moderator']);
    }

    // --- Authorization matrix ---

    public function test_the_owning_candidate_can_see_the_video_call(): void
    {
        $candidate = User::factory()->create();
        $application = Application::factory()->create(['candidate_id' => $candidate->id]);
        $interview = Interview::factory()->create(['application_id' => $application->id]);

        $response = $this->actingAs($candidate, 'sanctum')->getJson("/api/applications/{$application->id}");

        $response->assertOk();
        $this->assertSame(
            config('services.jaas.app_id').'/'.strtolower($interview->fresh()->video_room),
            $response->json('data.interview.video_call.room'),
        );
    }

    public function test_another_candidate_cannot_see_the_video_call(): void
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

    public function test_hr_can_see_the_video_call(): void
    {
        $hr = User::factory()->hr()->create();
        $interview = Interview::factory()->create();

        $response = $this->actingAs($hr, 'sanctum')->getJson('/api/interviews');

        $response->assertOk();
        $this->assertSame(
            config('services.jaas.app_id').'/'.strtolower($interview->fresh()->video_room),
            $response->json('data.0.video_call.room'),
        );
    }

    public function test_assistant_hr_can_see_the_video_call(): void
    {
        $assistantHr = User::factory()->assistantHr()->create();
        $interview = Interview::factory()->create();

        $response = $this->actingAs($assistantHr, 'sanctum')->getJson('/api/interviews');

        $response->assertOk();
        $this->assertSame(
            config('services.jaas.app_id').'/'.strtolower($interview->fresh()->video_room),
            $response->json('data.0.video_call.room'),
        );
    }

    public function test_admin_can_see_the_video_call(): void
    {
        $admin = User::factory()->admin()->create();
        $interview = Interview::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/interviews');

        $response->assertOk();
        $this->assertSame(
            config('services.jaas.app_id').'/'.strtolower($interview->fresh()->video_room),
            $response->json('data.0.video_call.room'),
        );
    }

    public function test_guest_cannot_see_the_video_call(): void
    {
        Interview::factory()->create();

        $this->getJson('/api/interviews')->assertStatus(401);
    }

    public function test_a_cancelled_interviews_call_is_not_exposed_to_the_owning_candidate(): void
    {
        $candidate = User::factory()->create();
        $application = Application::factory()->create(['candidate_id' => $candidate->id]);
        Interview::factory()->create([
            'application_id' => $application->id,
            'status' => InterviewStatus::Cancelled,
        ]);

        $response = $this->actingAs($candidate, 'sanctum')->getJson("/api/applications/{$application->id}");

        $response->assertOk()->assertJsonMissingPath('data.interview.video_call');
    }

    public function test_a_cancelled_interviews_call_is_not_exposed_to_hr(): void
    {
        $hr = User::factory()->hr()->create();
        $interview = Interview::factory()->create(['status' => InterviewStatus::Cancelled]);

        $this->actingAs($hr, 'sanctum')
            ->getJson('/api/interviews')
            ->assertOk()
            ->assertJsonMissingPath('data.0.video_call');

        // Cancellation also shouldn't be the trigger that lazily backfills
        // a room that was never generated — there's nothing to join, so
        // nothing worth persisting either.
        $this->assertNull($interview->fresh()->video_room);
    }
}
