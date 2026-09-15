<?php

namespace App\Enums;

enum EducationLevel: string
{
    case Highschool = 'highschool';
    case Bachelors = 'bachelors';
    case Masters = 'masters';
    case Phd = 'phd';

    /**
     * Relative ranking used to compare education levels against a job requirement.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Highschool => 1,
            self::Bachelors => 2,
            self::Masters => 3,
            self::Phd => 4,
        };
    }
}
