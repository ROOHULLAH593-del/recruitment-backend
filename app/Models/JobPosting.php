<?php

namespace App\Models;

use App\Enums\EducationLevel;
use App\Enums\JobStatus;
use Database\Factories\JobPostingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'title',
    'description',
    'department',
    'required_skills',
    'min_experience',
    'education_requirement',
    'salary_min',
    'salary_max',
    'location',
    'status',
    'posted_by',
])]
class JobPosting extends Model
{
    /** @use HasFactory<JobPostingFactory> */
    use HasFactory;

    protected $table = 'job_postings';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required_skills' => 'array',
            'min_experience' => 'integer',
            'education_requirement' => EducationLevel::class,
            'salary_min' => 'decimal:2',
            'salary_max' => 'decimal:2',
            'status' => JobStatus::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * @return HasMany<Application, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'job_id');
    }
}
