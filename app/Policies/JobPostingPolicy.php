<?php

namespace App\Policies;

use App\Enums\JobStatus;
use App\Models\JobPosting;
use App\Models\User;

class JobPostingPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, JobPosting $jobPosting): bool
    {
        return $user->isStaff() || $jobPosting->status === JobStatus::Open;
    }

    /**
     * Determine whether the user can create models. assistant_hr is
     * deliberately excluded — view-only access to job postings, per its
     * role definition — so this checks isHrOrAdmin() specifically rather
     * than the broader isStaff() used for view() above.
     */
    public function create(User $user): bool
    {
        return $user->isHrOrAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, JobPosting $jobPosting): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isHr() && $jobPosting->posted_by === $user->id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, JobPosting $jobPosting): bool
    {
        return $this->update($user, $jobPosting);
    }
}
