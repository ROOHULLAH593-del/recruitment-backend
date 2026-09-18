<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * This endpoint only ever creates candidates (see AuthController::register),
     * so username/CNIC are required here unconditionally rather than being
     * conditioned on a role field.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            // CNIC is encrypted at rest, so its uniqueness can't be checked
            // with a plain `unique:users,cnic` rule — that would compare
            // against ciphertext. cnic_hash (a deterministic HMAC) is the
            // column that's actually safe to look up by.
            'cnic' => [
                'required',
                'string',
                'regex:/^\d{5}-\d{7}-\d$/',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (User::where('cnic_hash', User::hashCnic($value))->exists()) {
                        $fail('This CNIC is already registered.');
                    }
                },
            ],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cnic.regex' => 'The CNIC must be in the format XXXXX-XXXXXXX-X.',
        ];
    }
}
