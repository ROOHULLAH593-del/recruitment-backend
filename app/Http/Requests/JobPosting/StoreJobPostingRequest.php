<?php

namespace App\Http\Requests\JobPosting;

use App\Enums\EducationLevel;
use App\Enums\JobStatus;
use App\Models\JobPosting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJobPostingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', JobPosting::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'department' => ['nullable', 'string', 'max:255'],
            'required_skills' => ['nullable', 'array'],
            'required_skills.*' => ['string', 'max:100'],
            'min_experience' => ['nullable', 'integer', 'min:0'],
            'education_requirement' => ['nullable', Rule::enum(EducationLevel::class)],
            'salary_min' => ['nullable', 'numeric', 'min:0'],
            'salary_max' => ['nullable', 'numeric', 'gte:salary_min'],
            'location' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(JobStatus::class)],
        ];
    }
}
