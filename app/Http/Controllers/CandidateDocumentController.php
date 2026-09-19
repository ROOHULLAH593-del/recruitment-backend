<?php

namespace App\Http\Controllers;

use App\Enums\CandidateDocumentType;
use App\Models\CandidateProfile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CandidateDocumentController extends Controller
{
    /**
     * Stream a single document back for the given profile. Authorization is
     * split by document type — see CandidateProfilePolicy::viewDocument():
     * CNIC front/back are owner+Admin only, everything else is also visible
     * to HR/assistant_hr.
     */
    public function show(CandidateProfile $profile, CandidateDocumentType $documentType): StreamedResponse
    {
        $this->authorize('viewDocument', [$profile, $documentType]);

        $path = $profile->{$documentType->column()};

        abort_if($path === null, 404, 'No document has been uploaded for this slot.');
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }
}
