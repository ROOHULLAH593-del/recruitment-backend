<?php

namespace App\Http\Controllers;

use App\Http\Requests\User\UpdatePasswordRequest;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    /**
     * Update the authenticated user's own password.
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        // The User model casts `password` as `hashed`, so assigning the plain
        // validated value here is correct — Eloquent hashes it on save.
        $request->user()->update([
            'password' => $request->validated('password'),
        ]);

        return response()->json(['message' => 'Password updated.']);
    }
}
