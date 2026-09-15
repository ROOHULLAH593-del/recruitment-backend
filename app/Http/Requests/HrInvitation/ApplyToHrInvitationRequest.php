<?php

namespace App\Http\Requests\HrInvitation;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ApplyToHrInvitationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Public/unauthenticated by design — the invitation token itself is
     * the credential for this one action, not a signed-in user. Whether
     * the token is actually still usable (pending_use, not expired) is a
     * business-rule check the controller makes, not an authorization
     * concern a Policy could express (there's no User to check against).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
