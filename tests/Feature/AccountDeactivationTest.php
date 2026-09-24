<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Interview;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountDeactivationTest extends TestCase
{
    use RefreshDatabase;

    private const DEACTIVATED_MESSAGE = 'This account has been deactivated. Please contact an administrator.';

    // --- Authorization: Admin-only ---

    public function test_admin_can_deactivate_an_hr_account(): void
    {
        $admin = User::factory()->admin()->create();
        $hr = User::factory()->hr()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/staff/{$hr->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.deactivated_at', fn ($value) => $value !== null);

        $this->assertTrue($hr->fresh()->isDeactivated());
    }

    public function test_admin_can_deactivate_an_assistant_hr_account(): void
    {
        $admin = User::factory()->admin()->create();
        $assistantHr = User::factory()->assistantHr()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/staff/{$assistantHr->id}/deactivate")
            ->assertOk();

        $this->assertTrue($assistantHr->fresh()->isDeactivated());
    }

    public function test_hr_cannot_deactivate_another_hr_account(): void
    {
        $hr = User::factory()->hr()->create();
        $otherHr = User::factory()->hr()->create();

        $this->actingAs($hr, 'sanctum')
            ->postJson("/api/admin/staff/{$otherHr->id}/deactivate")
            ->assertForbidden();

        $this->assertFalse($otherHr->fresh()->isDeactivated());
    }

    public function test_assistant_hr_cannot_deactivate_an_hr_account(): void
    {
        $assistantHr = User::factory()->assistantHr()->create();
        $hr = User::factory()->hr()->create();

        $this->actingAs($assistantHr, 'sanctum')
            ->postJson("/api/admin/staff/{$hr->id}/deactivate")
            ->assertForbidden();
    }

    public function test_candidate_cannot_deactivate_an_hr_account(): void
    {
        $candidate = User::factory()->candidate()->create();
        $hr = User::factory()->hr()->create();

        $this->actingAs($candidate, 'sanctum')
            ->postJson("/api/admin/staff/{$hr->id}/deactivate")
            ->assertForbidden();
    }

    public function test_a_guest_cannot_deactivate_an_account(): void
    {
        $hr = User::factory()->hr()->create();

        $this->postJson("/api/admin/staff/{$hr->id}/deactivate")->assertUnauthorized();
    }

    public function test_deactivating_a_candidate_account_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $candidate = User::factory()->candidate()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/staff/{$candidate->id}/deactivate")
            ->assertStatus(422)
            ->assertJsonPath('errors.user.0', 'Only HR or Assistant HR accounts can be deactivated.');

        $this->assertFalse($candidate->fresh()->isDeactivated());
    }

    public function test_deactivating_an_admin_account_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/staff/{$otherAdmin->id}/deactivate")
            ->assertStatus(422)
            ->assertJsonPath('errors.user.0', 'Only HR or Assistant HR accounts can be deactivated.');
    }

    public function test_deactivating_an_already_deactivated_account_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $hr = User::factory()->hr()->create(['deactivated_at' => now()]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/staff/{$hr->id}/deactivate")
            ->assertStatus(422)
            ->assertJsonPath('errors.user.0', 'This account is already deactivated.');
    }

    // --- Reactivation: Admin-only ---

    public function test_admin_can_reactivate_a_deactivated_account(): void
    {
        $admin = User::factory()->admin()->create();
        $hr = User::factory()->hr()->create(['deactivated_at' => now()]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/staff/{$hr->id}/reactivate")
            ->assertOk()
            ->assertJsonPath('data.deactivated_at', null);

        $this->assertFalse($hr->fresh()->isDeactivated());
    }

    public function test_hr_cannot_reactivate_another_hr_account(): void
    {
        $hr = User::factory()->hr()->create();
        $deactivatedHr = User::factory()->hr()->create(['deactivated_at' => now()]);

        $this->actingAs($hr, 'sanctum')
            ->postJson("/api/admin/staff/{$deactivatedHr->id}/reactivate")
            ->assertForbidden();

        $this->assertTrue($deactivatedHr->fresh()->isDeactivated());
    }

    public function test_reactivating_an_already_active_account_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $hr = User::factory()->hr()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/staff/{$hr->id}/reactivate")
            ->assertStatus(422)
            ->assertJsonPath('errors.user.0', 'This account is not deactivated.');
    }

    // --- Login is rejected, with a message distinct from lockout ---

    public function test_a_deactivated_account_cannot_log_in(): void
    {
        $hr = User::factory()->hr()->create(['password' => 'password123', 'deactivated_at' => now()]);

        $this->postJson('/api/login', [
            'identifier' => $hr->email,
            'password' => 'password123',
        ])->assertStatus(422)->assertJsonPath('errors.identifier.0', self::DEACTIVATED_MESSAGE);
    }

    public function test_the_deactivated_message_is_distinct_from_the_lockout_message(): void
    {
        $hr = User::factory()->hr()->create(['password' => 'password123', 'deactivated_at' => now()]);

        $response = $this->postJson('/api/login', [
            'identifier' => $hr->email,
            'password' => 'password123',
        ]);

        $response->assertJsonPath(
            'errors.identifier.0',
            fn (string $message) => $message !== 'Too many failed attempts. Please reset your password.',
        );
    }

    public function test_a_deactivated_and_locked_account_shows_the_deactivated_message_not_the_lockout_one(): void
    {
        // Deactivation is a superseding admin decision — it must not be
        // masked by an unrelated, independently-clearable lockout.
        $hr = User::factory()->hr()->create([
            'password' => 'password123',
            'failed_login_attempts' => 3,
            'locked_at' => now(),
            'deactivated_at' => now(),
        ]);

        $this->postJson('/api/login', [
            'identifier' => $hr->email,
            'password' => 'password123',
        ])->assertStatus(422)->assertJsonPath('errors.identifier.0', self::DEACTIVATED_MESSAGE);
    }

    public function test_reactivating_allows_login_again(): void
    {
        $admin = User::factory()->admin()->create();
        $hr = User::factory()->hr()->create(['password' => 'password123', 'deactivated_at' => now()]);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/staff/{$hr->id}/reactivate")->assertOk();

        $this->postJson('/api/login', [
            'identifier' => $hr->email,
            'password' => 'password123',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    // --- Already-issued tokens stop working immediately ---

    public function test_deactivating_revokes_an_already_issued_token_immediately(): void
    {
        $hr = User::factory()->hr()->create();
        $token = $hr->createToken('api')->plainTextToken;

        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('api')->plainTextToken;

        $this->withToken($token)->getJson('/api/user')->assertOk();

        // The sanctum guard caches whichever user it resolves for the rest
        // of this test's lifetime (it's a singleton on the container, which
        // persists across these calls) — without forgetting it here, every
        // later request would keep answering as $hr regardless of which
        // token it actually carries, masking exactly the behavior this
        // test exists to catch. Same reasoning before each of the
        // differently-authenticated calls below.
        $this->app['auth']->forgetGuards();
        $this->withToken($adminToken)->postJson("/api/admin/staff/{$hr->id}/deactivate")->assertOk();

        // The same token, unchanged — proves the token itself was revoked,
        // not merely that a fresh login would now be blocked.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
        $this->assertSame(0, $hr->tokens()->count());
    }

    public function test_deactivating_one_accounts_token_does_not_affect_another_accounts(): void
    {
        $hr = User::factory()->hr()->create();
        $otherHr = User::factory()->hr()->create();
        $hrToken = $hr->createToken('api')->plainTextToken;
        $otherToken = $otherHr->createToken('api')->plainTextToken;

        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('api')->plainTextToken;

        $this->withToken($adminToken)->postJson("/api/admin/staff/{$hr->id}/deactivate")->assertOk();

        // See the note in the previous test — the sanctum guard's resolved
        // user is cached for the rest of the test unless forgotten.
        $this->app['auth']->forgetGuards();
        $this->withToken($hrToken)->getJson('/api/user')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken)->getJson('/api/user')->assertOk();
    }

    // --- Historical data stays intact and correctly attributed ---

    public function test_a_deactivated_hrs_job_postings_remain_intact_and_attributed(): void
    {
        $hr = User::factory()->hr()->create(['name' => 'Pat Rivera']);
        $job = JobPosting::factory()->create(['posted_by' => $hr->id]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/staff/{$hr->id}/deactivate")->assertOk();

        $this->assertDatabaseHas('job_postings', ['id' => $job->id, 'posted_by' => $hr->id]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/jobs');
        $listed = collect($response->json('data'))->firstWhere('id', $job->id);
        $this->assertNotNull($listed, 'The deactivated HR\'s job posting should still be listed.');
        $this->assertSame('Pat Rivera', $listed['posted_by']['name']);
    }

    public function test_a_deactivated_interviewers_interview_remains_intact_and_attributed(): void
    {
        $interviewer = User::factory()->hr()->create(['name' => 'Jordan Lee']);
        $interview = Interview::factory()->create(['interviewer_id' => $interviewer->id]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/staff/{$interviewer->id}/deactivate")->assertOk();

        $this->assertDatabaseHas('interviews', ['id' => $interview->id, 'interviewer_id' => $interviewer->id]);

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/interviews/{$interview->id}");
        $response->assertOk()->assertJsonPath('data.interviewer.name', 'Jordan Lee');
    }

    public function test_a_deactivated_hrs_reviewed_application_status_change_remains_intact(): void
    {
        $hr = User::factory()->hr()->create();
        $job = JobPosting::factory()->create(['posted_by' => $hr->id]);
        $application = Application::factory()->status(ApplicationStatus::Shortlisted)->create(['job_id' => $job->id]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/staff/{$hr->id}/deactivate")->assertOk();

        // The status HR set before deactivation is still exactly what's on
        // record — nothing about the review itself is touched or hidden.
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'shortlisted']);
    }

    // --- Listing (drives the Settings UI) ---

    public function test_admin_can_list_staff_including_deactivated_ones(): void
    {
        $admin = User::factory()->admin()->create();
        $hr = User::factory()->hr()->create();
        $deactivatedAssistant = User::factory()->assistantHr()->create(['deactivated_at' => now()]);
        User::factory()->candidate()->create(); // never listed here

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/staff?per_page=100');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($hr->id));
        $this->assertTrue($ids->contains($deactivatedAssistant->id));
        $this->assertSame(2, $ids->count());
    }

    public function test_hr_cannot_list_staff(): void
    {
        $hr = User::factory()->hr()->create();

        $this->actingAs($hr, 'sanctum')->getJson('/api/admin/staff')->assertForbidden();
    }
}
