<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_email(): void
    {
        User::factory()->create([
            'email' => 'jane@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/login', [
            'identifier' => 'jane@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.email', 'jane@example.com')
            ->assertJsonStructure(['user', 'token']);
    }

    public function test_user_can_login_with_username(): void
    {
        User::factory()->create([
            'username' => 'janecandidate',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/login', [
            'identifier' => 'janecandidate',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.username', 'janecandidate')
            ->assertJsonStructure(['user', 'token']);
    }

    public function test_user_can_login_with_cnic(): void
    {
        $user = User::factory()->withCnic('12345-1234567-1')->create([
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/login', [
            'identifier' => '12345-1234567-1',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['user', 'token']);
    }

    public function test_login_fails_with_wrong_password_via_email(): void
    {
        User::factory()->create([
            'email' => 'jane@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/login', [
            'identifier' => 'jane@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('identifier');
    }

    public function test_login_fails_with_wrong_password_via_username(): void
    {
        User::factory()->create([
            'username' => 'janecandidate',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/login', [
            'identifier' => 'janecandidate',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('identifier');
    }

    public function test_login_fails_with_wrong_password_via_cnic(): void
    {
        User::factory()->withCnic('12345-1234567-1')->create([
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/login', [
            'identifier' => '12345-1234567-1',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('identifier');
    }

    public function test_login_fails_with_an_identifier_that_does_not_exist(): void
    {
        $response = $this->postJson('/api/login', [
            'identifier' => 'nobody@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('identifier');
    }

    public function test_login_error_message_does_not_reveal_whether_the_identifier_exists(): void
    {
        User::factory()->create(['email' => 'jane@example.com', 'password' => 'password123']);

        $existingIdentifierResponse = $this->postJson('/api/login', [
            'identifier' => 'jane@example.com',
            'password' => 'wrong-password',
        ]);

        $unknownIdentifierResponse = $this->postJson('/api/login', [
            'identifier' => 'nobody@example.com',
            'password' => 'wrong-password',
        ]);

        $this->assertSame(
            $existingIdentifierResponse->json('message'),
            $unknownIdentifierResponse->json('message'),
        );
    }

    public function test_authenticated_user_can_fetch_their_own_profile_via_user_endpoint(): void
    {
        $user = User::factory()->hr()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/user');

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', 'hr');
    }

    public function test_guest_cannot_access_the_user_endpoint(): void
    {
        $this->getJson('/api/user')->assertStatus(401);
    }

    // --- Token expiry (config('sanctum.expiration'), 1 week) ---

    public function test_a_freshly_issued_token_is_accepted(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $this->withToken($token)->getJson('/api/user')->assertOk();
    }

    public function test_a_token_still_under_a_week_old_is_accepted(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $user->tokens()->first()->forceFill(['created_at' => now()->subDays(6)])->save();

        $this->withToken($token)->getJson('/api/user')->assertOk();
    }

    public function test_a_token_older_than_a_week_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $user->tokens()->first()->forceFill(['created_at' => now()->subDays(8)])->save();

        $this->withToken($token)->getJson('/api/user')->assertStatus(401);
    }
}
