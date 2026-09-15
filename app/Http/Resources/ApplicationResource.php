<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'job' => new JobPostingResource($this->whenLoaded('job')),
            'candidate' => new UserResource($this->whenLoaded('candidate')),
            'status' => $this->status->value,
            'allowed_status_transitions' => array_map(
                fn ($status) => $status->value,
                $this->status->allowedManualTransitions(),
            ),
            'match_score' => $this->match_score,
            'interview' => new InterviewResource($this->whenLoaded('interview')),
            'applied_at' => $this->applied_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
