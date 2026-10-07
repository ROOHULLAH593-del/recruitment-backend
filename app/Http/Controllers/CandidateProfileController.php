<?php

namespace App\Http\Controllers;

use App\Enums\CandidateDocumentType;
use App\Enums\ResumeParseOutcome;
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
        $result = $resumeParsingService->parse($request->file('resume')->get());

        if ($result->outcome === ResumeParseOutcome::Ok) {
            return response()->json(['data' => $result->data]);
        }

        // Each message below ends by pointing at manual entry explicitly —
        // found during an audit that a generic failure left candidates
        // unsure whether the form below was even still usable.
        [$status, $message] = match ($result->outcome) {
            ResumeParseOutcome::Busy => [503, 'The AI service is busy right now. Please try again in a minute, or fill in your details below.'],
            ResumeParseOutcome::Unreadable => [422, "We couldn't read this file. If it is password-protected, remove the password and try again, or fill in your details below."],
            ResumeParseOutcome::Empty => [422, "We couldn't find any details in this file. It may be a scan or an image. Please fill in your details below."],
            ResumeParseOutcome::Unavailable => [503, 'Resume auto-fill is unavailable right now. Please fill in your details below.'],
        };

        return response()->json([
            'category' => $result->outcome->value,
            'message' => $message,
        ], $status);
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
