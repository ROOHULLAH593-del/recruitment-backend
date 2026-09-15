<?php

namespace App\Policies;

use App\Models\HrInvitation;
use App\Models\User;

class HrInvitationPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can accept the invitation (creating a
     * real account from its submitted applicant details).
     */
    public function accept(User $user, HrInvitation $hrInvitation): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can reject the invitation.
     */
    public function reject(User $user, HrInvitation $hrInvitation): bool
    {
        return $user->isAdmin();
    }
}
