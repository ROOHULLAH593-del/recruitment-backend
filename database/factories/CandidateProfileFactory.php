<?php

namespace Database\Factories;

use App\Enums\EducationLevel;
use App\Models\CandidateProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CandidateProfile>
 */
class CandidateProfileFactory extends Factory
{
    public const SKILL_POOL = [
        'PHP', 'Laravel', 'JavaScript', 'React', 'Vue', 'Node.js', 'MySQL',
        'PostgreSQL', 'Docker', 'AWS', 'Customer Service', 'Sales',
        'Communication', 'Excel', 'Python', 'CRM Software', 'Typing',
        'Technical Support', 'Zendesk', 'Salesforce',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->candidate(),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->address(),
            'date_of_birth' => fake()->dateTimeBetween('-55 years', '-18 years'),
            'resume_text' => fake()->paragraphs(3, true),
            'skills' => fake()->randomElements(self::SKILL_POOL, fake()->numberBetween(2, 6)),
            'education_level' => fake()->randomElement(EducationLevel::cases()),
            'years_experience' => fake()->numberBetween(0, 15),
        ];
    }
}
