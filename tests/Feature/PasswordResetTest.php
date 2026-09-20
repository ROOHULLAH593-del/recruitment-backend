<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_generates_a_valid_reset_link_for_an_existing_user(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            // Points at the React SPA's reset page, not a Blade route.
            $url = $notification->toMail($user)->actionUrl;

            return str_contains($url, config('app.frontend_url').'/reset-password')
                && str_contains($url, 'token=')
                && str_contains($url, 'email='.urlencode($user->email));
        });

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_forgot_password_gives_the_same_response_for_an_unknown_email(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com']);

        $response->assertOk()->assertJsonPath(
            'message',
            'If an account exists for that email, a password reset link has been sent.',
        );
        Notification::assertNothingSent();
    }

    public function test_reset_password_with_a_valid_token_changes_the_password(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword123']);
        $token = Password::createToken($user);

        $response = $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewPassword456',
            'password_confirmation' => 'NewPassword456',
        ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('NewPassword456', $user->fresh()->password));
    }

    public function test_reset_password_clears_the_failed_attempt_counter_and_lock(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword123']);
        $user->update(['failed_login_attempts' => 3, 'locked_at' => now()]);
        $token = Password::createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewPassword456',
            'password_confirmation' => 'NewPassword456',
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertSame(0, $fresh->failed_login_attempts);
        $this->assertFalse($fresh->isLocked());
    }

    public function test_account_can_log_in_normally_after_a_password_reset(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword123']);
        $user->update(['failed_login_attempts' => 3, 'locked_at' => now()]);
        $token = Password::createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewPassword456',
            'password_confirmation' => 'NewPassword456',
        ])->assertOk();

        $this->postJson('/api/login', [
            'identifier' => $user->email,
            'password' => 'NewPassword456',
        ])->assertOk()->assertJsonStructure(['user', 'token']);
    }

    public function test_reset_password_with_an_invalid_token_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword123']);

        $response = $this->postJson('/api/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'NewPassword456',
            'password_confirmation' => 'NewPassword456',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertTrue(Hash::check('OldPassword123', $user->fresh()->password));
    }

    public function test_reset_password_with_an_expired_token_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword123']);
        $token = Password::createToken($user);

        // The broker's own expiry (config('auth.passwords.users.expire'),
        // 60 minutes) is enforced by comparing the token row's created_at —
        // backdating it is the standard way to simulate an expired token
        // without actually waiting an hour.
        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->update(['created_at' => now()->subHours(2)]);

        $response = $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewPassword456',
            'password_confirmation' => 'NewPassword456',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertTrue(Hash::check('OldPassword123', $user->fresh()->password));
    }

    public function test_a_reset_token_cannot_be_used_twice(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword123']);
        $token = Password::createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewPassword456',
            'password_confirmation' => 'NewPassword456',
        ])->assertOk();

        $secondAttempt = $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'AnotherPassword789',
            'password_confirmation' => 'AnotherPassword789',
        ]);

        $secondAttempt->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertTrue(Hash::check('NewPassword456', $user->fresh()->password));
    }

    public function test_reset_password_requires_matching_confirmation(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $response = $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewPassword456',
            'password_confirmation' => 'SomethingElse789',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }
}
