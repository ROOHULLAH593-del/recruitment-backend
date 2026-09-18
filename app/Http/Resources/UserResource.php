<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $canViewCnic = $viewer && ($viewer->id === $this->id || $viewer->isAdmin());

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'username' => $this->username,
            'role' => $this->role->value,
            'candidate_profile' => new CandidateProfileResource($this->whenLoaded('candidateProfile')),
            'created_at' => $this->created_at,
            // Only the account owner and Admin ever see the decrypted CNIC
            // — every other viewer (HR/assistant_hr reviewing an
            // application, another candidate, etc.) gets the key omitted
            // entirely rather than null, so its absence can't be mistaken
            // for "this user has no CNIC on file".
            'cnic' => $this->when($canViewCnic, fn () => $this->cnic),
        ];
    }
}
