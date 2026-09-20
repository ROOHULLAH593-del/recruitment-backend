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

#[Fillable(['name', 'email', 'username', 'cnic', 'cnic_hash', 'password', 'role', 'failed_login_attempts', 'locked_at'])]
#[Hidden(['password', 'remember_token', 'cnic', 'cnic_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Consecutive wrong-password attempts (on an already-identified
     * account) before the account locks. See AuthController::login().
     */
    public const MAX_FAILED_LOGIN_ATTEMPTS = 3;

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
            'cnic' => 'encrypted',
            'locked_at' => 'datetime',
        ];
    }

    /**
     * True once failed login attempts have crossed the lockout threshold.
     * There's no timer-based expiry — this only clears via a successful
     * login or password reset (see AuthController).
     */
    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * Keyed, deterministic, one-way hash of a CNIC — used both to populate
     * `cnic_hash` on write and to look it up on login, so the two call
     * sites can never drift out of sync with each other.
     */
    public static function hashCnic(string $cnic): string
    {
        return hash_hmac('sha256', $cnic, config('app.key'));
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
