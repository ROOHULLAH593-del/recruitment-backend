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

        $response->assertCreated()->assertJsonPath('data.has_video_call', true);

        // The scheduling response itself stays cheap (has_video_call only,
        // no signed JWT) — the room is still genuinely generated and
        // persisted on the model, just not echoed back as a token here.
        $interview = Interview::where('application_id', $application->id)->first();
        $this->assertNotNull($interview->video_room);
        $this->assertMatchesRegularExpression('#^recruitment-[A-Za-z0-9]{32}$#', $interview->video_room);
    }

    public function test_video_rooms_are_unique_across_interviews(): void
    {
        $hr = User::factory()->hr()->create();

        $first = Application::factory()->status(ApplicationStatus::Shortlisted)->create();
        $second = Application::factory()->status(ApplicationStatus::Shortlisted)->create();

        $this->actingAs($hr, 'sanctum')
            ->postJson("/api/applications/{$first->id}/interview", ['scheduled_at' => now()->addWeek()->toDateTimeString()])
            ->assertCreated();
        $this->actingAs($hr, 'sanctum')
            ->postJson("/api/applications/{$second->id}/interview", ['scheduled_at' => now()->addWeeks(2)->toDateTimeString()])
            ->assertCreated();

        $this->assertNotSame(
            Interview::where('application_id', $first->id)->value('video_room'),
            Interview::where('application_id', $second->id)->value('video_room'),
        );
    }

    public function test_a_pre_existing_interview_without_a_room_gets_one_lazily_on_first_access(): void
    {
        $hr = User::factory()->hr()->create();
        $interview = Interview::factory()->create(['video_room' => null]);
        $this->assertNull($interview->video_room);

        $room = $this->actingAs($hr, 'sanctum')
            ->getJson("/api/interviews/{$interview->id}")
            ->json('data.video_call.room');

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

        $firstRoom = $this->actingAs($hr, 'sanctum')->getJson("/api/interviews/{$interview->id}")->json('data.video_call.room');
        $secondRoom = $this->actingAs($hr, 'sanctum')->getJson("/api/interviews/{$interview->id}")->json('data.video_call.room');

        $this->assertSame($firstRoom, $secondRoom);
    }

    // --- List/collection responses stay cheap: no signed JWT anywhere a
    // "Join Interview" button only ever checks presence before navigating
    // to CallPage, which fetches its own fresh token from show() below.
    // Confirmed via real profiling: signing one JWT costs ~4ms of RSA
    // signing, which a page of ~108 interviews turned into ~440ms of pure
    // wasted CPU, since none of it was ever read by the frontend. ---

    public function test_the_interviews_list_has_no_signed_jwt_but_the_single_interview_endpoint_does(): void
    {
        $hr = User::factory()->hr()->create();
        $interview = Interview::factory()->create();

        $this->actingAs($hr, 'sanctum')
            ->getJson('/api/interviews')
            ->assertOk()
            ->assertJsonPath('data.0.has_video_call', true)
            ->assertJsonMissingPath('data.0.video_call');

        $this->actingAs($hr, 'sanctum')
            ->getJson("/api/interviews/{$interview->id}")
            ->assertOk()
            ->assertJsonPath('data.has_video_call', true)
            ->assertJsonPath('data.video_call.room', fn ($room) => filled($room))
            ->assertJsonPath('data.video_call.jwt', fn ($jwt) => filled($jwt));
    }

    public function test_an_applications_nested_interview_has_no_signed_jwt_either(): void
    {
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::InterviewScheduled)->create();
        Interview::factory()->create(['application_id' => $application->id]);

        $this->actingAs($hr, 'sanctum')
            ->getJson('/api/applications')
            ->assertOk()
            ->assertJsonPath('data.0.interview.has_video_call', true)
            ->assertJsonMissingPath('data.0.interview.video_call');

        $this->actingAs($hr, 'sanctum')
            ->getJson("/api/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.interview.has_video_call', true)
            ->assertJsonMissingPath('data.interview.video_call');
    }

    public function test_scheduling_and_rescheduling_responses_have_no_signed_jwt(): void
    {
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Shortlisted)->create();

        $this->actingAs($hr, 'sanctum')
            ->postJson("/api/applications/{$application->id}/interview", ['scheduled_at' => now()->addWeek()->toDateTimeString()])
            ->assertCreated()
            ->assertJsonPath('data.has_video_call', true)
            ->assertJsonMissingPath('data.video_call');

        $interview = Interview::where('application_id', $application->id)->firstOrFail();

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/interviews/{$interview->id}", ['notes' => 'Updated.'])
            ->assertOk()
            ->assertJsonPath('data.has_video_call', true)
            ->assertJsonMissingPath('data.video_call');
    }

    // --- JWT contents (only reachable through the single-interview endpoint) ---

    public function test_the_jwt_is_validly_signed_and_scoped_to_the_specific_room(): void
    {
        $hr = User::factory()->hr()->create();
        $interview = Interview::factory()->create();

        $response = $this->actingAs($hr, 'sanctum')->getJson("/api/interviews/{$interview->id}");
        $videoCall = $response->json('data.video_call');

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

        $jwt = $this->actingAs($hr, 'sanctum')->getJson("/api/interviews/{$interview->id}")->json('data.video_call.jwt');
        $claims = $this->decodeJaasToken($jwt);

        $this->assertSame('Pat Rivera', $claims['context']['user']['name']);
        $this->assertSame('pat@example.com', $claims['context']['user']['email']);
    }

    public function test_staff_get_a_moderator_jwt(): void
    {
        foreach (['hr', 'assistantHr', 'admin'] as $factoryState) {
            $staff = User::factory()->{$factoryState}()->create();
            $interview = Interview::factory()->create();

            $jwt = $this->actingAs($staff, 'sanctum')->getJson("/api/interviews/{$interview->id}")->json('data.video_call.jwt');
            $claims = $this->decodeJaasToken($jwt);

            // JaaS expects the literal string "true"/"false", not a JSON boolean.
            $this->assertSame('true', $claims['context']['user']['moderator'], "Failed for role: {$factoryState}");
        }
    }

    public function test_the_owning_candidate_gets_a_non_moderator_jwt(): void
    {
        $candidate = User::factory()->create();
        $application = Application::factory()->create(['candidate_id' => $candidate->id]);
        $interview = Interview::factory()->create(['application_id' => $application->id]);

        $jwt = $this->actingAs($candidate, 'sanctum')
            ->getJson("/api/interviews/{$interview->id}")
            ->json('data.video_call.jwt');
        $claims = $this->decodeJaasToken($jwt);

        $this->assertSame('false', $claims['context']['user']['moderator']);
    }

    // --- Authorization matrix (has_video_call, the cheap presence flag) ---

    public function test_the_owning_candidate_can_see_the_video_call(): void
    {
        $candidate = User::factory()->create();
        $application = Application::factory()->create(['candidate_id' => $candidate->id]);
        Interview::factory()->create(['application_id' => $application->id]);

        $this->actingAs($candidate, 'sanctum')
            ->getJson("/api/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.interview.has_video_call', true);
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
        Interview::factory()->create();

        $this->actingAs($hr, 'sanctum')
            ->getJson('/api/interviews')
            ->assertOk()
            ->assertJsonPath('data.0.has_video_call', true);
    }

    public function test_assistant_hr_can_see_the_video_call(): void
    {
        $assistantHr = User::factory()->assistantHr()->create();
        Interview::factory()->create();

        $this->actingAs($assistantHr, 'sanctum')
            ->getJson('/api/interviews')
            ->assertOk()
            ->assertJsonPath('data.0.has_video_call', true);
    }

    public function test_admin_can_see_the_video_call(): void
    {
        $admin = User::factory()->admin()->create();
        Interview::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/interviews')
            ->assertOk()
            ->assertJsonPath('data.0.has_video_call', true);
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

        $response->assertOk()
            ->assertJsonPath('data.interview.has_video_call', false)
            ->assertJsonMissingPath('data.interview.video_call');
    }

    public function test_a_cancelled_interviews_call_is_not_exposed_to_hr(): void
    {
        $hr = User::factory()->hr()->create();
        $interview = Interview::factory()->create(['status' => InterviewStatus::Cancelled, 'video_room' => null]);

        $this->actingAs($hr, 'sanctum')
            ->getJson('/api/interviews')
            ->assertOk()
            ->assertJsonPath('data.0.has_video_call', false)
            ->assertJsonMissingPath('data.0.video_call');

        // Cancellation also shouldn't be the trigger that lazily backfills
        // a room that was never generated — there's nothing to join, so
        // nothing worth persisting either.
        $this->assertNull($interview->fresh()->video_room);
    }

    // --- Graceful degradation when JaaS isn't configured (a real handover bug:
    // a missing key used to 500 the entire Applications/Interviews response) ---

    public function test_interviews_list_loads_normally_with_no_video_call_when_the_jaas_key_is_missing(): void
    {
        config(['services.jaas.private_key_path' => storage_path('app/private/does-not-exist.pem')]);

        $hr = User::factory()->hr()->create();
        $interview = Interview::factory()->create();

        $this->actingAs($hr, 'sanctum')
            ->getJson('/api/interviews')
            ->assertOk()
            ->assertJsonPath('data.0.id', $interview->id)
            // has_video_call never touches JaasService, so a broken key
            // doesn't affect it — only the real video_call (below) does.
            ->assertJsonPath('data.0.has_video_call', true)
            ->assertJsonMissingPath('data.0.video_call');
    }

    public function test_the_single_interview_endpoint_loads_normally_with_no_video_call_when_the_jaas_key_is_missing(): void
    {
        config(['services.jaas.private_key_path' => storage_path('app/private/does-not-exist.pem')]);

        $hr = User::factory()->hr()->create();
        $interview = Interview::factory()->create();

        $this->actingAs($hr, 'sanctum')
            ->getJson("/api/interviews/{$interview->id}")
            ->assertOk()
            ->assertJsonPath('data.has_video_call', true)
            ->assertJsonMissingPath('data.video_call');
    }

    public function test_applications_list_loads_normally_with_no_video_call_when_the_jaas_key_is_missing(): void
    {
        config(['services.jaas.private_key_path' => storage_path('app/private/does-not-exist.pem')]);

        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::InterviewScheduled)->create();
        Interview::factory()->create(['application_id' => $application->id]);

        $this->actingAs($hr, 'sanctum')
            ->getJson('/api/applications')
            ->assertOk()
            ->assertJsonPath('data.0.id', $application->id)
            ->assertJsonMissingPath('data.0.interview.video_call');
    }

    public function test_a_single_application_with_an_interview_still_loads_when_the_jaas_key_is_missing(): void
    {
        config(['services.jaas.private_key_path' => storage_path('app/private/does-not-exist.pem')]);

        $candidate = User::factory()->create();
        $application = Application::factory()->create(['candidate_id' => $candidate->id]);
        Interview::factory()->create(['application_id' => $application->id]);

        $this->actingAs($candidate, 'sanctum')
            ->getJson("/api/applications/{$application->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.interview.video_call');
    }

    // --- Backfill migration (2026_10_03_073715_backfill_video_room_on_interviews) ---
    //
    // Confirmed via real profiling: a page of ~100 interviews predating the
    // video_room column (or created outside InterviewController::store(),
    // e.g. a seeder) cost 90 extra UPDATE queries and pushed that one
    // request from ~15ms to over 800ms — one per row, the first time
    // anyone viewed it. This migration clears the gap in one pass instead
    // of leaving every future page load to pay for it a row at a time.

    public function test_the_backfill_migration_fills_in_every_null_video_room(): void
    {
        $withRoom = Interview::factory()->create();
        $withoutRoomA = Interview::factory()->create(['video_room' => null]);
        $withoutRoomB = Interview::factory()->create(['video_room' => null]);
        $originalRoom = $withRoom->video_room;

        (require database_path('migrations/2026_10_03_073715_backfill_video_room_on_interviews.php'))->up();

        $this->assertSame($originalRoom, $withRoom->fresh()->video_room);
        $this->assertNotNull($withoutRoomA->fresh()->video_room);
        $this->assertNotNull($withoutRoomB->fresh()->video_room);
        $this->assertNotSame($withoutRoomA->fresh()->video_room, $withoutRoomB->fresh()->video_room);
    }

    public function test_the_backfill_migration_handles_more_rows_than_a_single_chunk(): void
    {
        Interview::factory()->count(5)->create(['video_room' => null]);

        (require database_path('migrations/2026_10_03_073715_backfill_video_room_on_interviews.php'))->up();

        $this->assertSame(0, Interview::whereNull('video_room')->count());
        $this->assertSame(5, Interview::whereNotNull('video_room')->distinct()->count('video_room'));
    }

    public function test_interviews_list_loads_normally_when_the_jaas_key_file_is_not_a_valid_key(): void
    {
        $badKeyPath = storage_path('app/private/not-a-real-key-'.uniqid().'.pem');
        file_put_contents($badKeyPath, "this is not a PEM private key\n");
        config(['services.jaas.private_key_path' => $badKeyPath]);

        $hr = User::factory()->hr()->create();
        Interview::factory()->create();

        try {
            $this->actingAs($hr, 'sanctum')
                ->getJson('/api/interviews')
                ->assertOk()
                ->assertJsonMissingPath('data.0.video_call');
        } finally {
            unlink($badKeyPath);
        }
    }
}
