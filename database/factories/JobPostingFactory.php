<?php

namespace Database\Factories;

use App\Enums\EducationLevel;
use App\Enums\JobStatus;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobPosting>
 */
class JobPostingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // PKR, monthly — realistic for Pakistani BPO/call-center roles: entry
        // agents around Rs 25,000-50,000, up through team-lead/senior roles
        // reaching Rs 150,000-200,000+. Numerically much larger than the old
        // USD-scale placeholder values, on purpose.
        $salaryMin = fake()->numberBetween(25, 100) * 1000;

        return [
            'title' => fake()->jobTitle(),
            'description' => fake()->paragraphs(4, true),
            'department' => fake()->randomElement(['Customer Support', 'Sales', 'Technical Support', 'Operations', 'HR']),
            'required_skills' => fake()->randomElements(CandidateProfileFactory::SKILL_POOL, fake()->numberBetween(2, 5)),
            'min_experience' => fake()->numberBetween(0, 5),
            'education_requirement' => fake()->randomElement(EducationLevel::cases()),
            'salary_min' => $salaryMin,
            'salary_max' => $salaryMin + fake()->numberBetween(15, 80) * 1000,
            'location' => fake()->city(),
            'status' => JobStatus::Open,
            'posted_by' => User::factory()->hr(),
        ];
    }

    /**
     * Indicate that the job posting is a draft.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => JobStatus::Draft,
        ]);
    }

    /**
     * Indicate that the job posting is closed.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => JobStatus::Closed,
        ]);
    }
}
