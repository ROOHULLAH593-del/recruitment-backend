<?php

namespace App\Services;

use App\Enums\EducationLevel;
use App\Models\CandidateProfile;
use App\Models\JobPosting;

class MatchScoreService
{
    private const SKILLS_WEIGHT = 0.5;

    private const EXPERIENCE_WEIGHT = 0.3;

    private const EDUCATION_WEIGHT = 0.2;

    /**
     * Calculate a candidate's 0-100 match score against a job posting.
     */
    public function score(CandidateProfile $profile, JobPosting $job): float
    {
        $score = $this->skillsScore($profile, $job) * self::SKILLS_WEIGHT
            + $this->experienceScore($profile, $job) * self::EXPERIENCE_WEIGHT
            + $this->educationScore($profile, $job) * self::EDUCATION_WEIGHT;

        return round($score, 2);
    }

    /**
     * Percentage overlap between the candidate's skills and the job's required skills.
     */
    private function skillsScore(CandidateProfile $profile, JobPosting $job): float
    {
        $required = $this->normalizeSkills($job->required_skills ?? []);

        if ($required === []) {
            return 100.0;
        }

        $candidateSkills = $this->normalizeSkills($profile->skills ?? []);
        $matched = array_intersect($required, $candidateSkills);

        return (count($matched) / count($required)) * 100;
    }

    /**
     * Full score once years_experience meets min_experience, proportional otherwise.
     */
    private function experienceScore(CandidateProfile $profile, JobPosting $job): float
    {
        if ($job->min_experience <= 0) {
            return 100.0;
        }

        if ($profile->years_experience >= $job->min_experience) {
            return 100.0;
        }

        return ($profile->years_experience / $job->min_experience) * 100;
    }

    /**
     * Full score once the candidate's education level meets or exceeds the requirement,
     * proportional to the requirement's rank otherwise.
     */
    private function educationScore(CandidateProfile $profile, JobPosting $job): float
    {
        $required = $job->education_requirement;

        if (! $required instanceof EducationLevel) {
            return 100.0;
        }

        $candidateLevel = $profile->education_level;

        if (! $candidateLevel instanceof EducationLevel) {
            return 0.0;
        }

        if ($candidateLevel->rank() >= $required->rank()) {
            return 100.0;
        }

        return ($candidateLevel->rank() / $required->rank()) * 100;
    }

    /**
     * @param  array<int, string>  $skills
     * @return array<int, string>
     */
    private function normalizeSkills(array $skills): array
    {
        return array_values(array_unique(array_map(
            fn (string $skill): string => strtolower(trim($skill)),
            $skills,
        )));
    }
}
