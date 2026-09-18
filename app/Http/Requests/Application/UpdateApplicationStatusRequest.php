<?php

namespace App\Http\Requests\Application;

use App\Enums\ApplicationStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateApplicationStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('updateStatus', $this->route('application'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ApplicationStatus::class)],
            // Only meaningful when status is "rejected" — the controller
            // ignores it for any other transition, so no extra validation
            // rule is needed to enforce that here.
            'rejection_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
