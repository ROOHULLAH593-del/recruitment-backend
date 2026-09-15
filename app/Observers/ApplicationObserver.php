<?php

namespace App\Observers;

use App\Models\Application;
use App\Services\MatchScoreService;

class ApplicationObserver
{
    public function __construct(
        private readonly MatchScoreService $matchScoreService,
    ) {}

    public function creating(Application $application): void
    {
        $profile = $application->candidate?->candidateProfile;
        $job = $application->job ?? $application->job()->first();

        if ($profile && $job) {
            $application->match_score = $this->matchScoreService->score($profile, $job);
        }
    }
}
