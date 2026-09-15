<?php

namespace Database\Factories;

use App\Enums\InvitationStatus;
use App\Enums\UserRole;
use App\Models\HrInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<HrInvitation>
 */
class HrInvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token' => Str::random(64),
            'role_offered' => UserRole::Hr,
            'invited_by' => User::factory()->admin(),
            'status' => InvitationStatus::PendingUse,
            'expires_at' => now()->addDays(7),
        ];
    }

    public function assistantHrRole(): static
    {
        return $this->state(fn (array $attributes) => [
            'role_offered' => UserRole::AssistantHr,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvitationStatus::Expired,
            'expires_at' => now()->subDay(),
        ]);
    }

    /**
     * Past its expiry but still marked pending_use — the state a row is in
     * right before something (a token lookup, an apply attempt) notices
     * and flips it, useful for testing that lazy-expiry logic itself.
     */
    public function pastDueButNotYetExpired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvitationStatus::PendingUse,
            'expires_at' => now()->subDay(),
        ]);
    }

    public function pendingReview(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvitationStatus::PendingReview,
            'applicant_name' => fake()->name(),
            'applicant_email' => fake()->unique()->safeEmail(),
            'applicant_password' => 'password',
            'submitted_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->pendingReview()->state(fn (array $attributes) => [
            'status' => InvitationStatus::Approved,
            'reviewed_by' => User::factory()->admin(),
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->pendingReview()->state(fn (array $attributes) => [
            'status' => InvitationStatus::Rejected,
            'reviewed_by' => User::factory()->admin(),
            'reviewed_at' => now(),
        ]);
    }
}
