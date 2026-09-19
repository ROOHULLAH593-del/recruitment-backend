<?php

namespace App\Models;

use App\Enums\CandidateDocumentType;
use App\Enums\EducationLevel;
use Database\Factories\CandidateProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'phone',
    'address',
    'date_of_birth',
    'resume_text',
    'skills',
    'education_level',
    'years_experience',
    'transcript_path',
    'cnic_front_path',
    'cnic_back_path',
    'fsc_certificate_path',
    'matric_certificate_path',
])]
class CandidateProfile extends Model
{
    /** @use HasFactory<CandidateProfileFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'skills' => 'array',
            'education_level' => EducationLevel::class,
            'years_experience' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Labels of the required documents (see CandidateDocumentType::isRequiredForApplying())
     * not yet on file — empty when the profile is complete enough to apply.
     *
     * @return array<int, string>
     */
    public function missingRequiredDocumentLabels(): array
    {
        return collect(CandidateDocumentType::cases())
            ->filter(fn (CandidateDocumentType $type) => $type->isRequiredForApplying())
            ->reject(fn (CandidateDocumentType $type) => filled($this->{$type->column()}))
            ->map(fn (CandidateDocumentType $type) => $type->label())
            ->values()
            ->all();
    }
}
