<?php

namespace App\Http\Requests\HrInvitation;

use App\Enums\UserRole;
use App\Models\HrInvitation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHrInvitationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', HrInvitation::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role_offered' => ['required', Rule::in([UserRole::Hr->value, UserRole::AssistantHr->value])],
        ];
    }
}
