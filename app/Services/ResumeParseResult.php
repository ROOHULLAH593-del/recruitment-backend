<?php

namespace App\Services;

use App\Enums\ResumeParseOutcome;

/**
 * What ResumeParsingService::parse() actually learned — distinct outcomes
 * so a caller (and ultimately the candidate) can tell a busy AI service
 * apart from a file it genuinely can't read, rather than seeing the same
 * generic failure either way.
 */
final class ResumeParseResult
{
    /**
     * @param  array{skills: array<int, string>, education_level: ?string, years_experience: int, resume_text: string}|null  $data
     */
    private function __construct(
        public readonly ResumeParseOutcome $outcome,
        public readonly ?array $data = null,
    ) {}

    /**
     * @param  array{skills: array<int, string>, education_level: ?string, years_experience: int, resume_text: string}  $data
     */
    public static function ok(array $data): self
    {
        return new self(ResumeParseOutcome::Ok, $data);
    }

    public static function busy(): self
    {
        return new self(ResumeParseOutcome::Busy);
    }

    public static function unreadable(): self
    {
        return new self(ResumeParseOutcome::Unreadable);
    }

    public static function empty(): self
    {
        return new self(ResumeParseOutcome::Empty);
    }

    public static function unavailable(): self
    {
        return new self(ResumeParseOutcome::Unavailable);
    }
}
