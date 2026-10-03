<?php

namespace App\Http\Resources;

use App\Enums\InterviewStatus;
use App\Services\JaasService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

class InterviewResource extends JsonResource
{
    /**
     * Whether to sign and include a real, usable video_call (room + JWT).
     * Defaults to false: a JWT costs ~4ms of RSA signing each, confirmed to
     * add ~440ms of pure wasted CPU time to a page of ~108 interviews, since
     * every "Join Interview" button in the app only ever checks
     * has_video_call before navigating to CallPage — which fetches its own
     * fresh token from InterviewController::show(), the one call site that
     * opts in via withVideoCallToken().
     *
     * Deliberately NOT a constructor parameter: Collection::mapInto(), what
     * ResourceCollection::collectResource() uses under ::collection(), calls
     * `new $class($value, $key)` — a second constructor argument here would
     * silently receive the array index instead of staying false, and PHP
     * coerces any non-zero int to true. Confirmed the hard way: adding it as
     * a constructor arg left ~90% of a list's rows still signing a real,
     * thrown-away JWT, with the resulting index-vs-flag confusion costing as
     * much time as having never fixed anything, while *looking* correct for
     * item 0 of any collection.
     */
    private bool $includeVideoCallToken = false;

    public function withVideoCallToken(): static
    {
        $this->includeVideoCallToken = true;

        return $this;
    }

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
            // Cheap and always present: whether a "Join Interview" button
            // should render at all. Never touches JaasService, so it never
            // signs a JWT and never lazily generates/persists video_room
            // either — that only happens below, and only when asked for.
            'has_video_call' => $canViewRoom,
            // Only the interview's own candidate and staff ever see this —
            // omitted entirely (not null) for everyone else, for anyone once
            // the interview is cancelled, for everyone if JaaS isn't
            // configured or its key can't be read (same reasoning as
            // GoogleCalendarService), and for every caller that didn't
            // opt in via withVideoCallToken().
            'video_call' => $this->when($canViewRoom && $this->includeVideoCallToken, function () use ($viewer) {
                $jaas = app(JaasService::class);
                $jwt = $jaas->tokenFor($this->resource, $viewer);

                if (! $jwt) {
                    return new MissingValue;
                }

                return [
                    'room' => $jaas->fullRoomName($this->resource),
                    'jwt' => $jwt,
                ];
            }),
            'created_at' => $this->created_at,
        ];
    }
}
