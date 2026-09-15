<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Deliberately minimal — this is what an unauthenticated visitor sees when
// opening their invite link, so it exposes just enough for the join form
// to render (which role they're being offered, when the link dies), never
// the admin-facing fields (invited_by, applicant_* once someone else has
// used it, reviewed_by, etc.) that HrInvitationResource carries.
class PublicInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'role_offered' => $this->role_offered->value,
            'expires_at' => $this->expires_at,
        ];
    }
}
