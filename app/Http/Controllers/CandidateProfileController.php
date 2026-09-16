<?php

namespace App\Http\Controllers;

use App\Http\Requests\CandidateProfile\UpdateCandidateProfileRequest;
use App\Http\Requests\CandidateProfile\UploadResumeRequest;
use App\Http\Resources\CandidateProfileResource;
use App\Services\ResumeParsingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CandidateProfileController extends Controller
{
    /**
     * Display the authenticated candidate's own profile.
     */
    public function show(Request $request): CandidateProfileResource
    {
        abort_unless($request->user()->isCandidate(), 403);

        return new CandidateProfileResource($request->user()->candidateProfile);
    }

    /**
     * Update the authenticated candidate's own profile.
     */
    public function update(UpdateCandidateProfileRequest $request): CandidateProfileResource
    {
        $profile = $request->user()->candidateProfile;

        $profile->update($request->validated());

        return new CandidateProfileResource($profile);
    }

    /**
     * Parse an uploaded resume PDF into suggested profile fields. This only
     * returns suggestions for the candidate to review — nothing is saved
     * here, saving still goes through update() above.
     */
    public function uploadResume(UploadResumeRequest $request, ResumeParsingService $resumeParsingService): JsonResponse
    {
        $suggested = $resumeParsingService->parse($request->file('resume')->get());

        if ($suggested === null) {
            return response()->json([
                'message' => "Couldn't auto-fill from this resume. Please enter your details manually.",
            ], 503);
        }

        return response()->json(['data' => $suggested]);
    }
}
