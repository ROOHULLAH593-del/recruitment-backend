<?php

namespace App\Observers;

use App\Models\Application;
use App\Models\CandidateProfile;
use App\Services\MatchScoreService;
use App\Services\SemanticMatchService;

class CandidateProfileObserver
{
    public function __construct(
        private readonly MatchScoreService $matchScoreService,
        private readonly SemanticMatchService $semanticMatchService,
    ) {}

    public function updated(CandidateProfile $profile): void
    {
        Application::where('candidate_id', $profile->user_id)
            ->with('job')
            ->get()
            ->each(function (Application $application) use ($profile) {
                if (! $application->job) {
                    return;
                }

                $application->match_score = $this->matchScoreService->score($profile, $application->job);
                $application->semantic_match_score = $this->semanticMatchService->score($profile, $application->job);
                $application->saveQuietly();
            });
    }
}
