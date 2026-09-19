<?php

namespace App\Http\Resources;

use App\Enums\CandidateDocumentType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CandidateProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'phone' => $this->phone,
            'address' => $this->address,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'resume_text' => $this->resume_text,
            'skills' => $this->skills,
            'education_level' => $this->education_level?->value,
            'years_experience' => $this->years_experience,
            // Presence only — never the stored path. Whether a slot is
            // filled isn't sensitive (unlike the document content itself,
            // which CandidateDocumentController gates per document type),
            // so this is safe to expose to any viewer of this resource.
            'documents' => collect(CandidateDocumentType::cases())->mapWithKeys(
                fn (CandidateDocumentType $type) => [$type->value => filled($this->{$type->column()})]
            )->all(),
        ];
    }
}
