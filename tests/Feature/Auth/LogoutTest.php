<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_logout_and_the_token_is_revoked(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $logoutResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/logout');

        $logoutResponse->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->app['auth']->forgetGuards();

        $subsequentRequest = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/user');

        $subsequentRequest->assertStatus(401);
    }

    public function test_guest_cannot_logout(): void
    {
        $this->postJson('/api/logout')->assertStatus(401);
    }

    public function test_logging_out_does_not_revoke_other_users_tokens(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $token = $user->createToken('test')->plainTextToken;
        $otherToken = $otherUser->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/logout')->assertOk();

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$otherToken}")
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.id', $otherUser->id);
    }
}
