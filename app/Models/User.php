<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /**
     * @return HasOne<CandidateProfile, $this>
     */
    public function candidateProfile(): HasOne
    {
        return $this->hasOne(CandidateProfile::class);
    }

    /**
     * @return HasMany<JobPosting, $this>
     */
    public function jobPostings(): HasMany
    {
        return $this->hasMany(JobPosting::class, 'posted_by');
    }

    /**
     * @return HasMany<Application, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'candidate_id');
    }

    /**
     * @return HasMany<Interview, $this>
     */
    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class, 'interviewer_id');
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isHr(): bool
    {
        return $this->role === UserRole::Hr;
    }

    public function isAssistantHr(): bool
    {
        return $this->role === UserRole::AssistantHr;
    }

    public function isHrOrAdmin(): bool
    {
        return $this->isHr() || $this->isAdmin();
    }

    /**
     * Broader than isHrOrAdmin(): true for every internal-staff role,
     * including assistant_hr. Deliberately kept separate from
     * isHrOrAdmin() (rather than widening that method) because the two
     * checks protect genuinely different things — isHrOrAdmin() gates job
     * posting writes, where assistant_hr is view-only, while isStaff()
     * gates applications/interviews/dashboard access, where assistant_hr
     * has the same full access hr does.
     */
    public function isStaff(): bool
    {
        return $this->isHr() || $this->isAdmin() || $this->isAssistantHr();
    }

    public function isCandidate(): bool
    {
        return $this->role === UserRole::Candidate;
    }
}
