<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AccountLockoutTest extends TestCase
{
    use RefreshDatabase;

    private function attemptWrongPassword(User $user): TestResponse
    {
        return $this->postJson('/api/login', [
            'identifier' => $user->email,
            'password' => 'wrong-password',
        ]);
    }

    public function test_attempts_before_lockout_show_the_generic_message(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $first = $this->attemptWrongPassword($user);
        $second = $this->attemptWrongPassword($user);

        $first->assertStatus(422)->assertJsonPath('errors.identifier.0', 'The provided credentials are incorrect.');
        $second->assertStatus(422)->assertJsonPath('errors.identifier.0', 'The provided credentials are incorrect.');
        $this->assertFalse($user->fresh()->isLocked());
        $this->assertSame(2, $user->fresh()->failed_login_attempts);
    }

    public function test_three_wrong_attempts_locks_the_account(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $this->attemptWrongPassword($user);
        $this->attemptWrongPassword($user);
        $third = $this->attemptWrongPassword($user);

        // The third attempt is the one that crosses the threshold, so it's
        // already locked by the time this same response is generated —
        // safe to say so explicitly, per the messaging nuance.
        $third->assertStatus(422)->assertJsonPath('errors.identifier.0', 'Too many failed attempts. Please reset your password.');
        $this->assertTrue($user->fresh()->isLocked());
        $this->assertSame(3, $user->fresh()->failed_login_attempts);
    }

    public function test_a_fourth_attempt_fails_even_with_the_correct_password_while_locked(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $this->attemptWrongPassword($user);
        $this->attemptWrongPassword($user);
        $this->attemptWrongPassword($user);
        $this->assertTrue($user->fresh()->isLocked());

        $fourth = $this->postJson('/api/login', [
            'identifier' => $user->email,
            'password' => 'password123',
        ]);

        $fourth->assertStatus(422)->assertJsonPath('errors.identifier.0', 'Too many failed attempts. Please reset your password.');
        $this->assertTrue($user->fresh()->isLocked());
    }

    public function test_locking_does_not_keep_incrementing_the_counter_past_the_threshold(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $this->attemptWrongPassword($user);
        $this->attemptWrongPassword($user);
        $this->attemptWrongPassword($user);
        $this->attemptWrongPassword($user);
        $this->attemptWrongPassword($user);

        $this->assertSame(3, $user->fresh()->failed_login_attempts);
    }

    public function test_a_successful_login_resets_the_failed_attempt_counter(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $this->attemptWrongPassword($user);
        $this->attemptWrongPassword($user);
        $this->assertSame(2, $user->fresh()->failed_login_attempts);

        $this->postJson('/api/login', [
            'identifier' => $user->email,
            'password' => 'password123',
        ])->assertOk();

        $this->assertSame(0, $user->fresh()->failed_login_attempts);
        $this->assertFalse($user->fresh()->isLocked());
    }

    public function test_a_nonexistent_identifier_shows_the_generic_message(): void
    {
        $response = $this->postJson('/api/login', [
            'identifier' => 'nobody@example.com',
            'password' => 'whatever123',
        ]);

        $response->assertStatus(422)->assertJsonPath('errors.identifier.0', 'The provided credentials are incorrect.');
    }
}
