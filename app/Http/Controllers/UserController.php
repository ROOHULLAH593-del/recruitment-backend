<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\User\UpdatePasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

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

    /**
     * List every HR/assistant_hr account. Admin-only — this exists purely
     * to drive the deactivate/reactivate UI in Settings, not as a general
     * staff directory.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $staff = User::query()
            ->whereIn('role', [UserRole::Hr, UserRole::AssistantHr])
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return UserResource::collection($staff);
    }

    /**
     * Deactivate an HR/assistant_hr account. Admin-only. Blocks future
     * logins (see AuthController::login()) and immediately revokes every
     * token already issued to this account — tokens otherwise never
     * expire, so without this a session started before deactivation would
     * keep working indefinitely.
     *
     * Deliberately doesn't touch job postings, interviews, or anything
     * else this account is attributed to — those stay exactly as they are,
     * still correctly pointing at this same user row.
     */
    public function deactivate(User $user): JsonResponse
    {
        $this->authorize('deactivate', $user);

        if (! $user->isHr() && ! $user->isAssistantHr()) {
            throw ValidationException::withMessages([
                'user' => ['Only HR or Assistant HR accounts can be deactivated.'],
            ]);
        }

        if ($user->isDeactivated()) {
            throw ValidationException::withMessages([
                'user' => ['This account is already deactivated.'],
            ]);
        }

        $user->update(['deactivated_at' => now()]);
        $user->tokens()->delete();

        return response()->json(['data' => new UserResource($user)]);
    }

    /**
     * Reactivate a previously deactivated HR/assistant_hr account.
     * Admin-only. Does not touch failed_login_attempts/locked_at — a
     * lockout and a deactivation are independent, so reactivating an
     * account that also happens to be locked still leaves it locked; that
     * clears the normal way, via a password reset.
     */
    public function reactivate(User $user): JsonResponse
    {
        $this->authorize('reactivate', $user);

        if (! $user->isDeactivated()) {
            throw ValidationException::withMessages([
                'user' => ['This account is not deactivated.'],
            ]);
        }

        $user->update(['deactivated_at' => null]);

        return response()->json(['data' => new UserResource($user)]);
    }
}
