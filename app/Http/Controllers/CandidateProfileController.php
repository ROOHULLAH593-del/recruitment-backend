<?php

namespace App\Http\Controllers;

use App\Http\Requests\CandidateProfile\UpdateCandidateProfileRequest;
use App\Http\Resources\CandidateProfileResource;
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
}
