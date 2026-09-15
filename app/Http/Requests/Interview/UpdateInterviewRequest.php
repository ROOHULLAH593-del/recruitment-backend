<?php

namespace App\Http\Requests\Interview;

use App\Enums\InterviewStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInterviewRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('interview'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'scheduled_at' => ['sometimes', 'date', 'after:now'],
            'google_calendar_event_id' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(InterviewStatus::class)],
            'notes' => ['nullable', 'string'],
        ];
    }
}
