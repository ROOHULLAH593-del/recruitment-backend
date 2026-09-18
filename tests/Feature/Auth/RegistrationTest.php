<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Jane Candidate',
            'email' => 'jane@example.com',
            'username' => 'janecandidate',
            'cnic' => '12345-1234567-1',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $overrides);
    }

    public function test_candidate_can_register_successfully(): void
    {
        $response = $this->postJson('/api/register', $this->validPayload());

        $response->assertCreated()->assertJsonStructure(['message']);

        $user = User::where('email', 'jane@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->role === UserRole::Candidate);
        $this->assertSame('janecandidate', $user->username);
        $this->assertSame('12345-1234567-1', $user->cnic);
        $this->assertSame(User::hashCnic('12345-1234567-1'), $user->cnic_hash);
        $this->assertNotNull($user->candidateProfile, 'A CandidateProfile should be created on registration.');
    }

    public function test_registration_does_not_return_an_auth_token(): void
    {
        $response = $this->postJson('/api/register', $this->validPayload());

        $response->assertCreated();
        $response->assertJsonMissingPath('token');
        $response->assertJsonMissingPath('user');
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->postJson('/api/register', $this->validPayload(['email' => 'taken@example.com']));

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_registration_fails_with_duplicate_username(): void
    {
        User::factory()->create(['username' => 'janecandidate']);

        $response = $this->postJson('/api/register', $this->validPayload(['email' => 'someoneelse@example.com']));

        $response->assertStatus(422)->assertJsonValidationErrors('username');
    }

    public function test_registration_fails_with_duplicate_cnic(): void
    {
        User::factory()->withCnic('12345-1234567-1')->create();

        $response = $this->postJson('/api/register', $this->validPayload([
            'email' => 'someoneelse@example.com',
            'username' => 'someoneelse',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('cnic');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidCnicProvider(): array
    {
        return [
            'no dashes' => ['1234512345671'],
            'too few digits' => ['1234-123456-1'],
            'too many digits' => ['123456-1234567-1'],
            'letters' => ['1234A-1234567-1'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidCnicProvider')]
    public function test_registration_fails_with_invalid_cnic_format(string $cnic): void
    {
        $response = $this->postJson('/api/register', $this->validPayload(['cnic' => $cnic]));

        $response->assertStatus(422)->assertJsonValidationErrors('cnic');
    }

    public function test_registration_fails_without_username(): void
    {
        $response = $this->postJson('/api/register', $this->validPayload(['username' => '']));

        $response->assertStatus(422)->assertJsonValidationErrors('username');
    }

    public function test_registration_fails_without_cnic(): void
    {
        $response = $this->postJson('/api/register', $this->validPayload(['cnic' => '']));

        $response->assertStatus(422)->assertJsonValidationErrors('cnic');
    }

    public function test_registration_fails_with_password_that_is_too_short(): void
    {
        $response = $this->postJson('/api/register', $this->validPayload([
            'password' => 'ab1',
            'password_confirmation' => 'ab1',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_registration_fails_with_letters_only_password(): void
    {
        $response = $this->postJson('/api/register', $this->validPayload([
            'password' => 'onlyletters',
            'password_confirmation' => 'onlyletters',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_registration_fails_with_numbers_only_password(): void
    {
        $response = $this->postJson('/api/register', $this->validPayload([
            'password' => '12345678',
            'password_confirmation' => '12345678',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_registration_fails_when_password_confirmation_does_not_match(): void
    {
        $response = $this->postJson('/api/register', $this->validPayload([
            'password_confirmation' => 'somethingelse123',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_registered_user_is_always_a_candidate_regardless_of_input(): void
    {
        $response = $this->postJson('/api/register', $this->validPayload(['role' => 'admin']));

        $response->assertCreated();
        $this->assertTrue(User::where('email', 'jane@example.com')->first()->role === UserRole::Candidate);
    }
}
