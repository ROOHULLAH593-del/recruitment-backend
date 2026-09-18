<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'job_id',
    'candidate_id',
    'status',
    'match_score',
    'semantic_match_score',
    'rejection_reason',
    'applied_at',
])]
class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'match_score' => 'decimal:2',
            'semantic_match_score' => 'decimal:2',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<JobPosting, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class, 'job_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'candidate_id');
    }

    /**
     * @return HasOne<Interview, $this>
     */
    public function interview(): HasOne
    {
        return $this->hasOne(Interview::class);
    }
}
