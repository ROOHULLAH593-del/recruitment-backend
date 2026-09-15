<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use App\Enums\UserRole;
use Database\Factories\HrInvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class HrInvitation extends Model
{
    /** @use HasFactory<HrInvitationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'token',
        'role_offered',
        'invited_by',
        'status',
        'applicant_name',
        'applicant_email',
        'applicant_password',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role_offered' => UserRole::class,
            'status' => InvitationStatus::class,
            'applicant_password' => 'hashed',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Creates a pending invitation with a fresh, unguessable token and a
     * 7-day expiry — the one entry point that should ever construct an
     * invitation, so token generation/uniqueness and the expiry window
     * live in exactly one place rather than being repeated at every call
     * site (currently just HrInvitationController::store).
     */
    public static function createInvitation(UserRole $roleOffered, User $invitedBy): self
    {
        return self::create([
            'token' => self::generateUniqueToken(),
            'role_offered' => $roleOffered,
            'invited_by' => $invitedBy->id,
            'status' => InvitationStatus::PendingUse,
            'expires_at' => now()->addDays(7),
        ]);
    }

    /**
     * Marks the invitation expired if it's still pending_use but past its
     * expiry — called on every public token lookup so an expired
     * invitation reads (and rejects) as expired the moment anyone checks
     * it, rather than only when some scheduled job gets around to it.
     */
    public function expireIfPastDue(): void
    {
        if ($this->status === InvitationStatus::PendingUse && $this->expires_at->isPast()) {
            $this->update(['status' => InvitationStatus::Expired]);
        }
    }

    private static function generateUniqueToken(): string
    {
        do {
            $token = Str::random(64);
        } while (self::where('token', $token)->exists());

        return $token;
    }
}
