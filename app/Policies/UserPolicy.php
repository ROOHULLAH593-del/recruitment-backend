<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Determine whether the user can view the staff list. Admin-only —
     * this is purely for the deactivate/reactivate UI, not a general
     * staff directory.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can deactivate the given account.
     * Admin-only — not even HR may act on other HR/assistant_hr accounts.
     * Which roles are actually eligible to be deactivated (HR/assistant_hr,
     * not another admin or a candidate) is a business rule checked in
     * UserController::deactivate(), not here — this only gates who may
     * attempt the action at all.
     */
    public function deactivate(User $user, User $target): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can reactivate the given account.
     * Admin-only, same as deactivate().
     */
    public function reactivate(User $user, User $target): bool
    {
        return $user->isAdmin();
    }
}
