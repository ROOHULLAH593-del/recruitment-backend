<?php

namespace App\Policies;

use App\Enums\CandidateDocumentType;
use App\Models\CandidateProfile;
use App\Models\User;

class CandidateProfilePolicy
{
    /**
     * Determine whether the user can view a specific document on this
     * profile. CNIC front/back are restricted to the owner and Admin only;
     * every other document type (transcript, FSC/Matric certificates) is
     * also visible to HR and assistant_hr.
     */
    public function viewDocument(User $user, CandidateProfile $candidateProfile, CandidateDocumentType $documentType): bool
    {
        $isOwner = $candidateProfile->user_id === $user->id;

        if ($documentType->isCnic()) {
            return $isOwner || $user->isAdmin();
        }

        return $isOwner || $user->isStaff();
    }
}
