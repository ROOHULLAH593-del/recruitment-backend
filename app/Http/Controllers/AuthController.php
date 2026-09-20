<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\CandidateProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // Shown once an account is actually locked. Unlike the deliberately
    // vague pre-lockout message, this is safe to be specific — reaching
    // this point already proves the requester knows a valid identifier for
    // this account, so naming the reason adds no enumeration risk, only
    // clarity about what to do next.
    private const LOCKOUT_MESSAGE = 'Too many failed attempts. Please reset your password.';

    private const GENERIC_CREDENTIALS_MESSAGE = 'The provided credentials are incorrect.';

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

        if (! $user) {
            // Same generic message as a wrong password below — otherwise
            // the response would leak which identifiers are registered.
            throw ValidationException::withMessages([
                'identifier' => [self::GENERIC_CREDENTIALS_MESSAGE],
            ]);
        }

        // A locked account can't log in via password at all, correct
        // password or not — the only way back in is a password reset, not
        // a timer, so there's nothing to check here besides the flag.
        if ($user->isLocked()) {
            throw ValidationException::withMessages([
                'identifier' => [self::LOCKOUT_MESSAGE],
            ]);
        }

        if (! Hash::check($request->validated('password'), $user->password)) {
            $user->increment('failed_login_attempts');

            if ($user->failed_login_attempts >= User::MAX_FAILED_LOGIN_ATTEMPTS) {
                $user->update(['locked_at' => now()]);

                throw ValidationException::withMessages([
                    'identifier' => [self::LOCKOUT_MESSAGE],
                ]);
            }

            throw ValidationException::withMessages([
                'identifier' => [self::GENERIC_CREDENTIALS_MESSAGE],
            ]);
        }

        if ($user->failed_login_attempts > 0) {
            $user->update(['failed_login_attempts' => 0]);
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

    /**
     * Send a password reset link. Always the same response regardless of
     * whether the email matched an account — this endpoint is reachable by
     * anyone with just an email address, so (unlike login, where the
     * account is already identified by the time it matters) this is where
     * the anti-enumeration posture actually has to hold.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'If an account exists for that email, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->update([
                    'password' => $password,
                    'failed_login_attempts' => 0,
                    'locked_at' => null,
                ]);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json(['message' => 'Your password has been reset. Please log in.']);
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
