<?php

namespace App\Enums;

enum CandidateDocumentType: string
{
    case Transcript = 'transcript';
    case CnicFront = 'cnic_front';
    case CnicBack = 'cnic_back';
    case FscCertificate = 'fsc_certificate';
    case MatricCertificate = 'matric_certificate';

    /**
     * The `candidate_profiles` column this document's storage path lives in.
     */
    public function column(): string
    {
        return "{$this->value}_path";
    }

    /**
     * Human-readable name, used in the apply-time gate's validation message
     * so a candidate knows exactly what's missing.
     */
    public function label(): string
    {
        return match ($this) {
            self::Transcript => 'Transcript',
            self::CnicFront => 'CNIC (front)',
            self::CnicBack => 'CNIC (back)',
            self::FscCertificate => 'FSC Certificate',
            self::MatricCertificate => 'Matric Certificate',
        };
    }

    /**
     * Must be on file before a candidate can apply to a job. FSC/Matric
     * certificates are supporting documents, not gating ones.
     */
    public function isRequiredForApplying(): bool
    {
        return in_array($this, [self::Transcript, self::CnicFront, self::CnicBack], true);
    }

    /**
     * CNIC images are the one pair restricted to the owner and Admin only —
     * every other document type is also visible to HR/assistant_hr.
     */
    public function isCnic(): bool
    {
        return in_array($this, [self::CnicFront, self::CnicBack], true);
    }
}
