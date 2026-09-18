<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Deliberately untyped/unvalidated as email|username|CNIC —
            // AuthController::login checks it against all three, and this
            // request never leaks which one a given value was even meant
            // to be.
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }
}
