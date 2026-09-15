<?php

namespace App\Http\Controllers;

use App\Enums\InvitationStatus;
use App\Enums\UserRole;
use App\Http\Requests\HrInvitation\ApplyToHrInvitationRequest;
use App\Http\Requests\HrInvitation\StoreHrInvitationRequest;
use App\Http\Resources\HrInvitationResource;
use App\Http\Resources\PublicInvitationResource;
use App\Http\Resources\UserResource;
use App\Models\HrInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class HrInvitationController extends Controller
{
    /**
     * Create a new invitation for the given role. Admin-only.
     */
    public function store(StoreHrInvitationRequest $request): HrInvitationResource
    {
        $invitation = HrInvitation::createInvitation(
            UserRole::from($request->validated('role_offered')),
            $request->user(),
        );

        return new HrInvitationResource($invitation);
    }

    /**
     * List every invitation, across every status. Admin-only.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', HrInvitation::class);

        $invitations = HrInvitation::query()
            ->with(['invitedBy', 'reviewedBy'])
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 15));

        return HrInvitationResource::collection($invitations);
    }

    /**
     * Look up an invitation by its token. Public/unauthenticated — this is
     * what an invite link's join page calls to render itself. Route-model-
     * bound via {invitation:token} (see routes/api.php), so an unknown
     * token 404s before this method body even runs.
     */
    public function show(HrInvitation $invitation): PublicInvitationResource
    {
        $invitation->expireIfPastDue();

        abort_unless($invitation->status === InvitationStatus::PendingUse, 404);

        return new PublicInvitationResource($invitation);
    }

    /**
     * Submit applicant details against an invitation. Public/
     * unauthenticated — the token is the credential. Moves the invitation
     * to pending_review; it can never be applied to again after this,
     * regardless of what the admin later decides.
     */
    public function apply(ApplyToHrInvitationRequest $request, HrInvitation $invitation): JsonResponse
    {
        $invitation->expireIfPastDue();

        if ($invitation->status !== InvitationStatus::PendingUse) {
            throw ValidationException::withMessages([
                'token' => ['This invitation is no longer available.'],
            ]);
        }

        $invitation->update([
            'applicant_name' => $request->validated('name'),
            'applicant_email' => $request->validated('email'),
            'applicant_password' => $request->validated('password'),
            'status' => InvitationStatus::PendingReview,
            'submitted_at' => now(),
        ]);

        return response()->json([
            'message' => 'Application submitted. An administrator will review it shortly.',
            'status' => $invitation->status->value,
        ]);
    }

    /**
     * Accept an invitation: creates a real account with the submitted
     * applicant details and the role originally offered. Admin-only.
     */
    public function accept(Request $request, HrInvitation $invitation): JsonResponse
    {
        $this->authorize('accept', $invitation);

        if ($invitation->status !== InvitationStatus::PendingReview) {
            throw ValidationException::withMessages([
                'invitation' => ['Only invitations pending review can be accepted.'],
            ]);
        }

        if (User::where('email', $invitation->applicant_email)->exists()) {
            throw ValidationException::withMessages([
                'invitation' => ['A user with this email already exists.'],
            ]);
        }

        $user = User::create([
            'name' => $invitation->applicant_name,
            'email' => $invitation->applicant_email,
            'password' => $invitation->applicant_password,
            'role' => $invitation->role_offered,
        ]);

        $invitation->update([
            'status' => InvitationStatus::Approved,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'message' => 'Invitation accepted.',
            'invitation' => new HrInvitationResource($invitation),
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Reject an invitation. Admin-only. No account is created.
     */
    public function reject(Request $request, HrInvitation $invitation): HrInvitationResource
    {
        $this->authorize('reject', $invitation);

        if ($invitation->status !== InvitationStatus::PendingReview) {
            throw ValidationException::withMessages([
                'invitation' => ['Only invitations pending review can be rejected.'],
            ]);
        }

        $invitation->update([
            'status' => InvitationStatus::Rejected,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return new HrInvitationResource($invitation);
    }
}
