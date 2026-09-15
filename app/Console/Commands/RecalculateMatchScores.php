<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Services\MatchScoreService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:recalculate-match-scores')]
#[Description('Recalculate the match_score for every application')]
class RecalculateMatchScores extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(MatchScoreService $matchScoreService): int
    {
        $count = 0;

        Application::with(['candidate.candidateProfile', 'job'])
            ->chunkById(100, function ($applications) use ($matchScoreService, &$count) {
                foreach ($applications as $application) {
                    $profile = $application->candidate?->candidateProfile;
                    $job = $application->job;

                    if ($profile && $job) {
                        $application->match_score = $matchScoreService->score($profile, $job);
                        $application->saveQuietly();
                        $count++;
                    }
                }
            });

        $this->info("Recalculated match scores for {$count} application(s).");

        return self::SUCCESS;
    }
}
