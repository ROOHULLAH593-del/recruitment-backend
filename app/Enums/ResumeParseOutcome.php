<?php

namespace App\Enums;

/**
 * Why a resume parse did or didn't produce usable fields — distinct from a
 * bare null, which previously made a busy Gemini server, a locked PDF, and
 * a broken API key all look identical to the candidate.
 */
enum ResumeParseOutcome: string
{
    // Succeeded with real, usable fields.
    case Ok = 'ok';

    // A transient upstream condition (429, 503, or a request that timed out
    // outright) — worth retrying automatically, and worth telling the
    // candidate to simply try again shortly.
    case Busy = 'busy';

    // Gemini rejected the request itself (400) on an otherwise well-formed
    // call — the typical shape of a password-protected or corrupt PDF.
    // Retrying won't help; the file needs to change.
    case Unreadable = 'unreadable';

    // Gemini responded successfully but found nothing extractable (e.g. a
    // scanned/image-only page with no readable text).
    case Empty = 'empty';

    // Anything else: missing/invalid API key, 401/403, 404, or a response
    // that doesn't match the expected shape. Not the candidate's fault and
    // not something retrying fixes — an operator needs to look at it.
    case Unavailable = 'unavailable';
}
