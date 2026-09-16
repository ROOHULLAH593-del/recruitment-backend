<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Http\Requests\Application\UpdateApplicationStatusRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Models\JobPosting;
use App\Notifications\ApplicationStatusChanged;
use App\Notifications\OfferSent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class ApplicationController extends Controller
{
    /**
     * Apply to a job posting as the authenticated candidate.
     */
    public function store(Request $request, JobPosting $job): ApplicationResource|JsonResponse
    {
        $this->authorize('create', Application::class);

        if ($job->status !== JobStatus::Open) {
            throw ValidationException::withMessages([
                'job' => ['This job posting is not currently open for applications.'],
            ]);
        }

        if (Application::where('job_id', $job->id)->where('candidate_id', $request->user()->id)->exists()) {
            return response()->json([
                'message' => 'You have already applied to this job.',
            ], 409);
        }

        $application = Application::create([
            'job_id' => $job->id,
            'candidate_id' => $request->user()->id,
            'status' => ApplicationStatus::Applied,
            'applied_at' => now(),
        ]);

        return new ApplicationResource($application->load('job'));
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Application::query()
            ->with(['job', 'candidate.candidateProfile', 'interview'])
            ->orderByDesc('applied_at')
            ->orderByDesc('id');

        if ($request->user()->isCandidate()) {
            $query->where('candidate_id', $request->user()->id);
        }

        return ApplicationResource::collection($query->paginate($request->integer('per_page', 15)));
    }

    /**
     * Display the specified resource.
     */
    public function show(Application $application): ApplicationResource
    {
        $this->authorize('view', $application);

        return new ApplicationResource($application->load(['job', 'candidate.candidateProfile', 'interview']));
    }

    /**
     * Update the application's status.
     */
    public function updateStatus(UpdateApplicationStatusRequest $request, Application $application): ApplicationResource
    {
        $newStatus = ApplicationStatus::from($request->validated('status'));

        if (! $application->status->canTransitionTo($newStatus)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot change status from \"{$application->status->value}\" to \"{$newStatus->value}\"."],
            ]);
        }

        $previousStatus = $application->status;

        $application->update([
            'status' => $newStatus,
        ]);

        if ($application->wasChanged('status')) {
            if ($application->status === ApplicationStatus::Offered) {
                $application->candidate->notify(new OfferSent($application));
            } else {
                $application->candidate->notify(new ApplicationStatusChanged($application, $previousStatus));
            }
        }

        return new ApplicationResource($application->load(['job', 'candidate.candidateProfile', 'interview']));
    }
}
