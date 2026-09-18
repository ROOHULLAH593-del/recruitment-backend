<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::Candidate,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user is an HR user.
     */
    public function hr(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Hr,
        ]);
    }

    /**
     * Indicate that the user is an assistant HR user.
     */
    public function assistantHr(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::AssistantHr,
        ]);
    }

    /**
     * Indicate that the user is an admin.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Admin,
        ]);
    }

    /**
     * Indicate that the user is a candidate.
     */
    public function candidate(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Candidate,
        ]);
    }

    /**
     * Give the user a CNIC, keeping `cnic` and `cnic_hash` in sync — tests
     * that set `cnic` directly without also computing a matching hash would
     * silently break CNIC login lookups.
     */
    public function withCnic(string $cnic): static
    {
        return $this->state(fn (array $attributes) => [
            'cnic' => $cnic,
            'cnic_hash' => User::hashCnic($cnic),
        ]);
    }
}
