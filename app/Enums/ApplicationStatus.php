<?php

namespace App\Enums;

enum ApplicationStatus: string
{
    case Applied = 'applied';
    case Shortlisted = 'shortlisted';
    case InterviewScheduled = 'interview_scheduled';
    case Interviewed = 'interviewed';
    case Offered = 'offered';
    case Rejected = 'rejected';
    case Hired = 'hired';

    /**
     * The statuses a human can manually move this application to from here.
     *
     * This governs manual HR-initiated status changes only. Automatic
     * system transitions (interview scheduling/completion/cancellation)
     * bypass this and are applied directly to the model.
     *
     * @return array<int, self>
     */
    public function allowedManualTransitions(): array
    {
        return match ($this) {
            self::Applied => [self::Shortlisted, self::Rejected],
            self::Shortlisted => [self::InterviewScheduled, self::Applied, self::Rejected],
            self::InterviewScheduled => [self::Interviewed, self::Rejected],
            self::Interviewed => [self::Offered, self::Shortlisted, self::Rejected],
            self::Offered => [self::Hired, self::Rejected],
            self::Rejected => [self::Shortlisted],
            self::Hired => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedManualTransitions(), true);
    }
}
