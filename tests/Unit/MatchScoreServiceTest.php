<?php

namespace Tests\Unit;

use App\Enums\EducationLevel;
use App\Models\CandidateProfile;
use App\Models\JobPosting;
use App\Services\MatchScoreService;
use Tests\TestCase;

class MatchScoreServiceTest extends TestCase
{
    private MatchScoreService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MatchScoreService;
    }

    private function profile(array $attributes = []): CandidateProfile
    {
        return new CandidateProfile(array_merge([
            'skills' => [],
            'years_experience' => 0,
            'education_level' => null,
        ], $attributes));
    }

    private function job(array $attributes = []): JobPosting
    {
        return new JobPosting(array_merge([
            'required_skills' => [],
            'min_experience' => 0,
            'education_requirement' => null,
        ], $attributes));
    }

    private function assertScore(float $expected, CandidateProfile $profile, JobPosting $job): void
    {
        $this->assertEqualsWithDelta($expected, $this->service->score($profile, $job), 0.001);
    }

    public function test_perfect_match_scores_100(): void
    {
        $profile = $this->profile([
            'skills' => ['PHP', 'React', 'MySQL'],
            'years_experience' => 5,
            'education_level' => EducationLevel::Masters,
        ]);
        $job = $this->job([
            'required_skills' => ['PHP', 'React', 'MySQL'],
            'min_experience' => 3,
            'education_requirement' => EducationLevel::Bachelors,
        ]);

        $this->assertScore(100.0, $profile, $job);
    }

    public function test_no_required_skills_gives_full_skills_credit(): void
    {
        $this->assertScore(100.0, $this->profile(), $this->job());
    }

    public function test_partial_skills_overlap_is_weighted_at_50_percent(): void
    {
        $profile = $this->profile(['skills' => ['PHP', 'React']]);
        $job = $this->job(['required_skills' => ['PHP', 'React', 'AWS', 'Docker']]);

        // skills: 2/4 = 50% * 0.5 weight = 25
        // experience: no requirement -> 100 * 0.3 = 30
        // education: no requirement -> 100 * 0.2 = 20
        $this->assertScore(75.0, $profile, $job);
    }

    public function test_skills_matching_is_case_insensitive_and_trims_whitespace(): void
    {
        $profile = $this->profile(['skills' => [' php ', 'REACT']]);
        $job = $this->job(['required_skills' => ['PHP', 'react']]);

        $this->assertScore(100.0, $profile, $job);
    }

    public function test_zero_min_experience_gives_full_experience_credit_regardless_of_candidate(): void
    {
        $profile = $this->profile(['years_experience' => 0]);
        $job = $this->job(['min_experience' => 0]);

        $this->assertScore(100.0, $profile, $job);
    }

    public function test_experience_below_requirement_is_proportional(): void
    {
        $profile = $this->profile(['years_experience' => 1]);
        $job = $this->job(['min_experience' => 4]);

        // experience: 1/4 = 25% * 0.3 = 7.5; skills/education have no requirement -> 50 + 20
        $this->assertScore(77.5, $profile, $job);
    }

    public function test_experience_meeting_requirement_exactly_gives_full_credit(): void
    {
        $profile = $this->profile(['years_experience' => 4]);
        $job = $this->job(['min_experience' => 4]);

        $this->assertScore(100.0, $profile, $job);
    }

    public function test_experience_exceeding_requirement_gives_full_credit(): void
    {
        $profile = $this->profile(['years_experience' => 10]);
        $job = $this->job(['min_experience' => 4]);

        $this->assertScore(100.0, $profile, $job);
    }

    public function test_no_education_requirement_gives_full_credit_regardless_of_candidate(): void
    {
        $profile = $this->profile(['education_level' => null]);
        $job = $this->job(['education_requirement' => null]);

        $this->assertScore(100.0, $profile, $job);
    }

    public function test_education_below_requirement_is_proportional_by_rank(): void
    {
        $profile = $this->profile(['education_level' => EducationLevel::Highschool]); // rank 1
        $job = $this->job(['education_requirement' => EducationLevel::Phd]); // rank 4

        // education: 1/4 = 25% * 0.2 = 5; skills/experience have no requirement -> 50 + 30
        $this->assertScore(85.0, $profile, $job);
    }

    public function test_education_meeting_requirement_exactly_gives_full_credit(): void
    {
        $profile = $this->profile(['education_level' => EducationLevel::Bachelors]);
        $job = $this->job(['education_requirement' => EducationLevel::Bachelors]);

        $this->assertScore(100.0, $profile, $job);
    }

    public function test_education_exceeding_requirement_gives_full_credit(): void
    {
        $profile = $this->profile(['education_level' => EducationLevel::Phd]);
        $job = $this->job(['education_requirement' => EducationLevel::Bachelors]);

        $this->assertScore(100.0, $profile, $job);
    }

    public function test_missing_candidate_education_with_a_requirement_scores_zero_for_education(): void
    {
        $profile = $this->profile(['education_level' => null]);
        $job = $this->job(['education_requirement' => EducationLevel::Bachelors]);

        // education: 0 * 0.2 = 0; skills/experience have no requirement -> 50 + 30
        $this->assertScore(80.0, $profile, $job);
    }

    public function test_score_is_rounded_to_two_decimal_places(): void
    {
        $profile = $this->profile(['skills' => ['PHP'], 'years_experience' => 1]);
        $job = $this->job(['required_skills' => ['PHP', 'React', 'AWS'], 'min_experience' => 3]);

        // skills: 1/3 = 33.333...% * 0.5 = 16.6667
        // experience: 1/3 = 33.333...% * 0.3 = 10.0
        // education: no requirement -> 20
        // total = 46.6667 -> rounds to 46.67
        $this->assertScore(46.67, $profile, $job);
    }

    public function test_combined_weighted_score_with_partial_credit_across_all_three_factors(): void
    {
        $profile = $this->profile([
            'skills' => ['PHP', 'React'],
            'years_experience' => 2,
            'education_level' => EducationLevel::Bachelors,
        ]);
        $job = $this->job([
            'required_skills' => ['PHP', 'React', 'AWS', 'Docker'],
            'min_experience' => 4,
            'education_requirement' => EducationLevel::Masters,
        ]);

        // skills: 2/4 = 50% * 0.5 = 25
        // experience: 2/4 = 50% * 0.3 = 15
        // education: bachelors(2)/masters(3) = 66.667% * 0.2 = 13.333
        $this->assertScore(53.33, $profile, $job);
    }

    public function test_complete_mismatch_scores_zero(): void
    {
        $profile = $this->profile([
            'skills' => ['Welding'],
            'years_experience' => 0,
            'education_level' => EducationLevel::Highschool,
        ]);
        $job = $this->job([
            'required_skills' => ['PHP', 'React'],
            'min_experience' => 5,
            'education_requirement' => EducationLevel::Phd,
        ]);

        // skills: 0/2 = 0; experience: 0/5 = 0; education: 1/4 = 25% * 0.2 = 5
        $this->assertScore(5.0, $profile, $job);
    }
}
