<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InterviewResource extends JsonResource
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
            'application_id' => $this->application_id,
            'application' => new ApplicationResource($this->whenLoaded('application')),
            'interviewer' => new UserResource($this->whenLoaded('interviewer')),
            'scheduled_at' => $this->scheduled_at,
            'google_calendar_event_id' => $this->google_calendar_event_id,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
