<?php

namespace Database\Factories;

use App\Enums\ApplicationStatus;
use App\Enums\InterviewStatus;
use App\Models\Application;
use App\Models\Interview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Interview>
 */
class InterviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_id' => Application::factory()->status(ApplicationStatus::InterviewScheduled),
            'interviewer_id' => User::factory()->hr(),
            'scheduled_at' => fake()->dateTimeBetween('now', '+3 weeks'),
            'status' => InterviewStatus::Scheduled,
        ];
    }
}
