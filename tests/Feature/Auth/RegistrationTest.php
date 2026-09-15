<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_candidate_can_register_successfully(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Jane Candidate',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.name', 'Jane Candidate')
            ->assertJsonPath('user.email', 'jane@example.com')
            ->assertJsonPath('user.role', 'candidate')
            ->assertJsonStructure(['user', 'token']);

        $user = User::where('email', 'jane@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->role === UserRole::Candidate);
        $this->assertNotNull($user->candidateProfile, 'A CandidateProfile should be created on registration.');
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->postJson('/api/register', [
            'name' => 'Someone Else',
            'email' => 'taken@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_registration_fails_with_password_that_is_too_short(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Jane Candidate',
            'email' => 'jane@example.com',
            'password' => 'ab1',
            'password_confirmation' => 'ab1',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_registration_fails_with_letters_only_password(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Jane Candidate',
            'email' => 'jane@example.com',
            'password' => 'onlyletters',
            'password_confirmation' => 'onlyletters',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_registration_fails_with_numbers_only_password(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Jane Candidate',
            'email' => 'jane@example.com',
            'password' => '12345678',
            'password_confirmation' => '12345678',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_registration_fails_when_password_confirmation_does_not_match(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Jane Candidate',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'somethingelse123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_registered_user_is_always_a_candidate_regardless_of_input(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Sneaky Applicant',
            'email' => 'sneaky@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
        ]);

        $response->assertCreated()->assertJsonPath('user.role', 'candidate');
        $this->assertTrue(User::where('email', 'sneaky@example.com')->first()->role === UserRole::Candidate);
    }
}
