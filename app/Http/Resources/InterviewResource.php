<?php

namespace App\Http\Resources;

use App\Enums\InterviewStatus;
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
        $viewer = $request->user();

        // Reuses InterviewPolicy::view() (own candidate or staff) rather
        // than re-deriving the same ownership check here, so the two never
        // drift apart. A cancelled interview has no active call worth
        // joining — the room is withheld even from an otherwise-authorized
        // viewer.
        $canViewRoom = $viewer
            && $viewer->can('view', $this->resource)
            && $this->status !== InterviewStatus::Cancelled;

        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'application' => new ApplicationResource($this->whenLoaded('application')),
            'interviewer' => new UserResource($this->whenLoaded('interviewer')),
            'scheduled_at' => $this->scheduled_at,
            'google_calendar_event_id' => $this->google_calendar_event_id,
            'status' => $this->status->value,
            'notes' => $this->notes,
            // Only the interview's own candidate and staff ever see this —
            // omitted entirely (not null) for everyone else, and for
            // anyone once the interview is cancelled. videoRoom() lazily
            // generates+persists the identifier on first access, so this
            // closure only runs (and only writes) when someone authorized
            // actually looks.
            'video_room' => $this->when($canViewRoom, fn () => $this->videoRoom()),
            'created_at' => $this->created_at,
        ];
    }
}
