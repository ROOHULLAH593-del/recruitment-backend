<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Enums\InterviewStatus;
use App\Http\Requests\Interview\StoreInterviewRequest;
use App\Http\Requests\Interview\UpdateInterviewRequest;
use App\Http\Resources\InterviewResource;
use App\Models\Application;
use App\Models\Interview;
use App\Notifications\ApplicationStatusChanged;
use App\Notifications\InterviewScheduled;
use App\Services\GoogleCalendarService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class InterviewController extends Controller
{
    private const ACTIVE_STATUSES = [InterviewStatus::Scheduled, InterviewStatus::Rescheduled];

    public function __construct(
        private readonly GoogleCalendarService $googleCalendarService,
    ) {}

    /**
     * Schedule an interview for the given application.
     */
    public function store(StoreInterviewRequest $request, Application $application): InterviewResource
    {
        if ($application->status === ApplicationStatus::Applied) {
            throw ValidationException::withMessages([
                'application' => ['This application must be shortlisted before scheduling an interview.'],
            ]);
        }

        if ($application->interview()->exists()) {
            throw ValidationException::withMessages([
                'application' => ['This application already has an interview scheduled.'],
            ]);
        }

        $scheduledAt = Carbon::parse($request->validated('scheduled_at'));

        if ($this->interviewerHasConflict($request->user()->id, $scheduledAt)) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['You already have another interview scheduled at this time.'],
            ]);
        }

        $interview = Interview::create([
            'status' => InterviewStatus::Scheduled,
            ...$request->validated(),
            'application_id' => $application->id,
            'interviewer_id' => $request->user()->id,
            'video_room' => Interview::generateVideoRoomIdentifier(),
        ]);

        $application->update(['status' => ApplicationStatus::InterviewScheduled]);

        $application->candidate->notify(new InterviewScheduled($interview));

        $interview->load(['application.job', 'application.candidate', 'interviewer']);
        $googleEventId = $this->googleCalendarService->createEvent($interview);

        if ($googleEventId) {
            $interview->update(['google_calendar_event_id' => $googleEventId]);
        }

        return new InterviewResource($interview);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateInterviewRequest $request, Interview $interview): InterviewResource
    {
        if ($request->has('scheduled_at')) {
            $scheduledAt = Carbon::parse($request->validated('scheduled_at'));

            if ($this->interviewerHasConflict($interview->interviewer_id, $scheduledAt, $interview->id)) {
                throw ValidationException::withMessages([
                    'scheduled_at' => ['The interviewer already has another interview scheduled at this time.'],
                ]);
            }
        }

        $interview->update($request->validated());
        $interview->load(['application.job', 'application.candidate', 'interviewer']);

        if ($interview->wasChanged('status')) {
            $application = $interview->application;
            $previousStatus = $application->status;

            if ($interview->status === InterviewStatus::Completed) {
                $application->update(['status' => ApplicationStatus::Interviewed]);
                $application->candidate->notify(new ApplicationStatusChanged($application, $previousStatus));
            } elseif ($interview->status === InterviewStatus::Cancelled) {
                $application->update(['status' => ApplicationStatus::Shortlisted]);
                $application->candidate->notify(new ApplicationStatusChanged($application, $previousStatus));

                $this->googleCalendarService->deleteEvent($interview);
                $interview->update(['google_calendar_event_id' => null]);
            } elseif ($interview->status === InterviewStatus::Rescheduled) {
                $application->candidate->notify(new InterviewScheduled($interview));
            }
        }

        if ($interview->wasChanged('scheduled_at')) {
            $this->googleCalendarService->updateEvent($interview);
        }

        return new InterviewResource($interview);
    }

    /**
     * Display the specified resource. Exists specifically so a client
     * (e.g. the video call page) can fetch one interview it already knows
     * the id of directly, rather than paginating through /interviews and
     * searching client-side — which silently fails once the list is large
     * enough that the interview in question isn't on the requested page.
     */
    public function show(Interview $interview): InterviewResource
    {
        $this->authorize('view', $interview);

        return new InterviewResource($interview->load(['application.job', 'application.candidate', 'interviewer']));
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Interview::query()
            ->with(['application.job', 'application.candidate', 'interviewer'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->user()->isCandidate()) {
            $query->whereHas('application', fn ($q) => $q->where('candidate_id', $request->user()->id));
        }

        return InterviewResource::collection($query->paginate($request->integer('per_page', 15)));
    }

    /**
     * Determine whether the given interviewer already has an active interview at this time.
     */
    private function interviewerHasConflict(int $interviewerId, Carbon $scheduledAt, ?int $excludingInterviewId = null): bool
    {
        return Interview::where('interviewer_id', $interviewerId)
            ->where('scheduled_at', $scheduledAt)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->when($excludingInterviewId, fn ($query) => $query->whereKeyNot($excludingInterviewId))
            ->exists();
    }
}
