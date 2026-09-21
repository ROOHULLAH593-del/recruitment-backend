<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_PASSWORD = 'Str0ng-Bootstrap-Pass';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function commandOptions(array $overrides = []): array
    {
        return array_merge([
            '--name' => 'First Admin',
            '--email' => 'first.admin@example.com',
            '--password' => self::VALID_PASSWORD,
        ], $overrides);
    }

    // --- Normal case ---

    public function test_it_creates_an_admin_account_on_a_fresh_database(): void
    {
        $this->artisan('admin:create', $this->commandOptions())
            ->expectsOutputToContain('Admin account created: first.admin@example.com')
            ->assertExitCode(0);

        $admin = User::where('email', 'first.admin@example.com')->firstOrFail();
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertSame('First Admin', $admin->name);
        $this->assertNotSame(self::VALID_PASSWORD, $admin->getRawOriginal('password'));
        $this->assertTrue(Hash::check(self::VALID_PASSWORD, $admin->password));
    }

    public function test_the_created_admin_can_log_in_through_the_api_as_admin(): void
    {
        $this->artisan('admin:create', $this->commandOptions())->assertExitCode(0);

        $this->postJson('/api/login', [
            'identifier' => 'first.admin@example.com',
            'password' => self::VALID_PASSWORD,
        ])->assertOk()
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonStructure(['token']);
    }

    public function test_existing_hr_and_candidate_accounts_do_not_count_as_an_admin(): void
    {
        User::factory()->hr()->create();
        User::factory()->candidate()->create();

        $this->artisan('admin:create', $this->commandOptions())->assertExitCode(0);

        $this->assertSame(1, User::where('role', UserRole::Admin)->count());
    }

    // --- Validation failures ---

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidInputProvider(): array
    {
        return [
            'missing name' => [['--name' => ''], 'name field is required'],
            'missing email' => [['--email' => ''], 'email field is required'],
            'malformed email' => [['--email' => 'not-an-email'], 'must be a valid email address'],
            'missing password' => [['--password' => ''], 'password field is required'],
            'password too short' => [['--password' => 'Ab1cdefgh'], 'at least 12 characters'],
            'password without a number' => [['--password' => 'Abcdefghijklmn'], 'at least one number'],
            'password without mixed case' => [['--password' => 'abcdefghijk123'], 'at least one uppercase and one lowercase letter'],
            'password without letters' => [['--password' => '123456789012345'], 'at least one letter'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidInputProvider')]
    public function test_invalid_input_is_rejected_and_nothing_is_created(array $overrides, string $expectedMessage): void
    {
        $this->artisan('admin:create', $this->commandOptions($overrides))
            ->expectsOutputToContain($expectedMessage)
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_omitted_options_are_rejected(): void
    {
        $this->artisan('admin:create')->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_an_email_already_used_by_another_account_is_rejected(): void
    {
        User::factory()->candidate()->create(['email' => 'first.admin@example.com']);

        $this->artisan('admin:create', $this->commandOptions())
            ->expectsOutputToContain('has already been taken')
            ->assertExitCode(1);

        $this->assertSame(0, User::where('role', UserRole::Admin)->count());
    }

    // --- Zero-admins-only guard ---

    public function test_it_is_refused_once_an_admin_exists(): void
    {
        User::factory()->admin()->create();

        $this->artisan('admin:create', $this->commandOptions())
            ->expectsOutputToContain('An admin account already exists')
            ->assertExitCode(1);

        $this->assertSame(1, User::where('role', UserRole::Admin)->count());
        $this->assertDatabaseMissing('users', ['email' => 'first.admin@example.com']);
    }

    public function test_running_it_twice_refuses_the_second_run(): void
    {
        $this->artisan('admin:create', $this->commandOptions())->assertExitCode(0);

        $this->artisan('admin:create', $this->commandOptions(['--email' => 'second.admin@example.com']))
            ->expectsOutputToContain('An admin account already exists')
            ->assertExitCode(1);

        $this->assertSame(1, User::where('role', UserRole::Admin)->count());
    }

    public function test_force_bypasses_the_guard_and_creates_an_additional_admin(): void
    {
        User::factory()->admin()->create();

        $this->artisan('admin:create', $this->commandOptions(['--force' => true]))
            ->expectsOutputToContain('Admin account created')
            ->assertExitCode(0);

        $this->assertSame(2, User::where('role', UserRole::Admin)->count());
    }

    public function test_force_does_not_bypass_validation(): void
    {
        User::factory()->admin()->create();

        $this->artisan('admin:create', $this->commandOptions(['--force' => true, '--password' => 'weak']))
            ->assertExitCode(1);

        $this->assertSame(1, User::where('role', UserRole::Admin)->count());
    }
}
