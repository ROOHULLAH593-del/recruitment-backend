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
            // Matches InterviewController::store(), which always sets this
            // at creation — a factory-made interview without one doesn't
            // reflect anything the real app ever produces, and silently
            // reintroduces the N+1 backfill query
            // 2026_10_03_073715_backfill_video_room_on_interviews exists to
            // get rid of. Tests covering that lazy-generation path itself
            // override this back to null explicitly.
            'video_room' => Interview::generateVideoRoomIdentifier(),
        ];
    }
}
