<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\CandidateProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $cnic = $request->validated('cnic');

        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'username' => $request->validated('username'),
            'cnic' => $cnic,
            'cnic_hash' => User::hashCnic($cnic),
            'password' => $request->validated('password'),
            'role' => UserRole::Candidate,
        ]);

        CandidateProfile::create([
            'user_id' => $user->id,
            'years_experience' => 0,
        ]);

        // No token/auto-login here — the frontend sends registered
        // candidates to /login instead, now that login itself accepts
        // email, username, or CNIC.
        return response()->json([
            'message' => 'Registration successful. Please log in.',
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $identifier = $request->validated('identifier');

        // Checked in this order — email, then username, then CNIC (via its
        // hash) — but since all three columns are unique on their own, at
        // most one of these ever matches regardless of order.
        $user = User::where('email', $identifier)->first()
            ?? User::where('username', $identifier)->first()
            ?? User::where('cnic_hash', User::hashCnic($identifier))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            // Deliberately the same field/message whether the identifier
            // matched nothing at all or matched a user whose password was
            // wrong — otherwise the response would leak which identifiers
            // are registered.
            throw ValidationException::withMessages([
                'identifier' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('api')->plainTextToken;

        // /login sits outside the auth:sanctum group, so nothing has
        // resolved a request-bound user yet — without this,
        // UserResource's owner-check would wrongly hide the just-logged-in
        // user's own CNIC from themselves on this very response. Set on
        // the container's bound request singleton (what UserResource
        // actually reads), not this method's injected $request — that's a
        // separate FormRequest instance produced for validation.
        app('request')->setUserResolver(fn () => $user);

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()->load('candidateProfile')),
        ]);
    }
}
