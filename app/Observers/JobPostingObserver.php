<?php

namespace App\Observers;

use App\Models\Application;
use App\Models\JobPosting;
use App\Services\MatchScoreService;
use App\Services\SemanticMatchService;

class JobPostingObserver
{
    public function __construct(
        private readonly MatchScoreService $matchScoreService,
        private readonly SemanticMatchService $semanticMatchService,
    ) {}

    public function updated(JobPosting $job): void
    {
        Application::where('job_id', $job->id)
            ->with('candidate.candidateProfile')
            ->get()
            ->each(function (Application $application) use ($job) {
                $profile = $application->candidate?->candidateProfile;

                if (! $profile) {
                    return;
                }

                $application->match_score = $this->matchScoreService->score($profile, $job);
                $application->semantic_match_score = $this->semanticMatchService->score($profile, $job);
                $application->saveQuietly();
            });
    }
}
