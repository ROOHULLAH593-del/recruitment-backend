<?php

namespace App\Http\Controllers;

use App\Enums\CandidateDocumentType;
use App\Http\Requests\CandidateProfile\UpdateCandidateProfileRequest;
use App\Http\Requests\CandidateProfile\UploadCandidateDocumentRequest;
use App\Http\Requests\CandidateProfile\UploadResumeRequest;
use App\Http\Resources\CandidateProfileResource;
use App\Services\ResumeParsingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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

    /**
     * Upload (or replace) one of the five document slots on the
     * authenticated candidate's own profile. The old file, if any, is
     * deleted from private storage so a replacement never leaves an
     * orphaned file behind.
     */
    public function uploadDocument(UploadCandidateDocumentRequest $request): CandidateProfileResource
    {
        $profile = $request->user()->candidateProfile;
        $documentType = CandidateDocumentType::from($request->validated('document_type'));
        $column = $documentType->column();

        if ($profile->{$column}) {
            Storage::disk('local')->delete($profile->{$column});
        }

        $path = $request->file('file')->store("candidate-documents/{$profile->id}", 'local');

        $profile->update([$column => $path]);

        return new CandidateProfileResource($profile);
    }
}
