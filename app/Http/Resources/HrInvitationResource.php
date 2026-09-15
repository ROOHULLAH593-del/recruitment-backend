<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HrInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'token' => $this->token,
            'role_offered' => $this->role_offered->value,
            'status' => $this->status->value,
            'invited_by' => new UserResource($this->whenLoaded('invitedBy')),
            'applicant_name' => $this->applicant_name,
            'applicant_email' => $this->applicant_email,
            'submitted_at' => $this->submitted_at,
            'reviewed_by' => new UserResource($this->whenLoaded('reviewedBy')),
            'reviewed_at' => $this->reviewed_at,
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
        ];
    }
}
