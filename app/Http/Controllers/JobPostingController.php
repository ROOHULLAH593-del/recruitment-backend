<?php

namespace App\Http\Controllers;

use App\Enums\JobStatus;
use App\Http\Requests\JobPosting\StoreJobPostingRequest;
use App\Http\Requests\JobPosting\UpdateJobPostingRequest;
use App\Http\Resources\JobPostingResource;
use App\Models\Application;
use App\Models\JobPosting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class JobPostingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user('sanctum');

        $query = JobPosting::query()->with('postedBy')->orderByDesc('created_at')->orderByDesc('id');

        if (! $user || $user->isCandidate()) {
            $query->where('status', JobStatus::Open);
        }

        return JobPostingResource::collection($query->paginate($request->integer('per_page', 15)));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreJobPostingRequest $request): JobPostingResource
    {
        $job = JobPosting::create([
            'status' => JobStatus::Draft,
            ...$request->validated(),
            'posted_by' => $request->user()->id,
        ]);

        return new JobPostingResource($job);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, JobPosting $job): JobPostingResource
    {
        $user = $request->user('sanctum');

        $canView = $job->status === JobStatus::Open
            || ($user && $user->isStaff())
            || ($user && Application::where('job_id', $job->id)->where('candidate_id', $user->id)->exists());

        abort_unless($canView, 404);

        return new JobPostingResource($job->load('postedBy'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateJobPostingRequest $request, JobPosting $job): JobPostingResource
    {
        $job->update($request->validated());

        return new JobPostingResource($job);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(JobPosting $job): JsonResponse
    {
        $this->authorize('delete', $job);

        if ($job->applications()->exists()) {
            throw ValidationException::withMessages([
                'job' => ['This job posting has existing applications and cannot be deleted. Close it instead.'],
            ]);
        }

        $job->delete();

        return response()->json(['message' => 'Job posting deleted.']);
    }
}
