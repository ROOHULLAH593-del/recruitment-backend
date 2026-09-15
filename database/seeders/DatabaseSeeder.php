<?php

namespace Database\Seeders;

use App\Enums\ApplicationStatus;
use App\Enums\InterviewStatus;
use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\Interview;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->admin()->create([
            'name' => 'System Admin',
            'email' => 'admin@recruitment.test',
        ]);

        $hrUsers = User::factory()->hr()->count(3)->create();

        $candidates = User::factory()->candidate()->count(10)->create();

        $candidates->each(function (User $candidate) {
            CandidateProfile::factory()->create([
                'user_id' => $candidate->id,
            ]);
        });

        $jobs = JobPosting::factory()
            ->recycle($hrUsers)
            ->count(5)
            ->create();

        $statuses = ApplicationStatus::cases();

        $applications = collect();

        foreach ($candidates as $index => $candidate) {
            $jobsAppliedTo = $jobs->random(fake()->numberBetween(1, 3));

            foreach ($jobsAppliedTo as $jobIndex => $job) {
                $status = $statuses[($index + $jobIndex) % count($statuses)];

                $applications->push(Application::factory()->create([
                    'candidate_id' => $candidate->id,
                    'job_id' => $job->id,
                    'status' => $status,
                ]));
            }
        }

        $interviewStatusMap = [
            ApplicationStatus::InterviewScheduled->value => InterviewStatus::Scheduled,
            ApplicationStatus::Interviewed->value => InterviewStatus::Completed,
            ApplicationStatus::Offered->value => InterviewStatus::Completed,
            ApplicationStatus::Hired->value => InterviewStatus::Completed,
        ];

        foreach ($applications as $application) {
            if (! isset($interviewStatusMap[$application->status->value])) {
                continue;
            }

            Interview::factory()->create([
                'application_id' => $application->id,
                'interviewer_id' => $hrUsers->random()->id,
                'status' => $interviewStatusMap[$application->status->value],
            ]);
        }
    }
}
