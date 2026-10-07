<?php

namespace App\Services;

use App\Enums\ResumeParseOutcome;
use App\Models\JobPosting;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * A non-AI, pure-PHP fallback for when every Gemini attempt has already
 * come back Busy or Unavailable (never tried for Unreadable or Empty —
 * those already have a real answer). Pulls text out of the PDF itself
 * with smalot/pdfparser (pure PHP, no exec/shell_exec, verified against
 * its source — the host this app deploys to doesn't allow those) and runs
 * a few simple, readable heuristics over it.
 *
 * Never fabricates: a PDF with no extractable text (a scan/image) keeps
 * whatever the AI already said rather than inventing fields from nothing,
 * and a genuinely encrypted PDF is reported as Unreadable — what the AI
 * would have said given the chance, confirmed empirically: Gemini itself
 * returns a clean 400 on an encrypted file, and smalot/pdfparser throws
 * its own clear "Secured pdf" exception for the same file.
 */
class LocalResumeExtractionService
{
    // The host this app deploys to disables set_time_limit, so this is the
    // only enforcement available: checked between discrete steps (after
    // the initial parse, and again before each page), not a hard interrupt
    // mid-call — a single pathological call still can't be aborted. Real
    // PDFs parse in milliseconds in practice; this is a backstop, not the
    // normal case.
    private const MAX_EXTRACTION_SECONDS = 10;

    private const MAX_PAGES = 10;

    private const MAX_TEXT_LENGTH = 20000;

    // Below this, treat it the same as "nothing extracted" — a scanned
    // page often still yields a handful of stray characters from its
    // structure, not real content.
    private const MIN_USABLE_TEXT_LENGTH = 20;

    /**
     * Checked highest-first, so a resume mentioning both is credited with
     * the higher one — same "highest completed education" semantics the
     * AI prompt already uses.
     *
     * @var array<string, list<string>>
     */
    private const EDUCATION_KEYWORDS = [
        'phd' => ['phd', 'ph.d', 'doctorate'],
        'masters' => ['master', 'msc', 'm.sc', 'mba', 'm.b.a'],
        'bachelors' => ['bachelor', 'bsc', 'b.sc', 'bba', 'b.b.a'],
        'highschool' => ['high school', 'intermediate', 'matric'],
    ];

    /**
     * A small floor on top of whatever's already in real job postings'
     * required_skills — common BPO/call-center and IT terms that might not
     * be in any posting yet.
     *
     * @var list<string>
     */
    private const BUILT_IN_SKILLS = [
        'Customer Service', 'Technical Support', 'Sales', 'Communication', 'Typing',
        'Zendesk', 'Salesforce', 'CRM Software', 'Microsoft Office', 'Excel',
        'PHP', 'Laravel', 'JavaScript', 'React', 'Vue', 'Node.js', 'Python',
        'MySQL', 'PostgreSQL', 'Docker', 'AWS',
    ];

    /**
     * @return ResumeParseResult Busy/Unavailable if nothing could be
     *                           filled (keeping $aiOutcome's own verdict), Unreadable if the PDF
     *                           turned out to be encrypted, or a "basic"-sourced Ok result.
     */
    public function extractAsFallback(string $pdfContents, ResumeParseOutcome $aiOutcome): ResumeParseResult
    {
        $start = microtime(true);
        [$text, $pageCount] = $this->extractText($pdfContents, $start);

        if ($text === false) {
            Log::warning('Local resume text extraction found an encrypted PDF; reporting it as unreadable.');

            return ResumeParseResult::unreadable();
        }

        if ($text === null || mb_strlen($text) < self::MIN_USABLE_TEXT_LENGTH) {
            Log::info('Local resume text extraction found no usable text; keeping the AI outcome.', [
                'ai_outcome' => $aiOutcome->value,
            ]);

            return $aiOutcome === ResumeParseOutcome::Busy
                ? ResumeParseResult::busy()
                : ResumeParseResult::unavailable();
        }

        $data = [
            'skills' => $this->matchSkills($text),
            'education_level' => $this->detectEducationLevel($text),
            'years_experience' => $this->detectYearsExperience($text),
            'resume_text' => mb_substr($text, 0, self::MAX_TEXT_LENGTH),
        ];

        Log::info('Local resume text extraction filled the form without AI.', [
            'duration_s' => round(microtime(true) - $start, 2),
            'page_count' => $pageCount,
            'text_length' => mb_strlen($text),
            'fields_filled' => array_keys(array_filter($data, fn ($value) => $value !== null && $value !== [] && $value !== 0)),
        ]);

        return ResumeParseResult::basic($data);
    }

    /**
     * @return array{0: string|null|false, 1: int} The extracted text (or
     *                                             null for "nothing usable", or false specifically for "this PDF
     *                                             is encrypted"), and the page count actually seen.
     */
    private function extractText(string $pdfContents, float $start): array
    {
        try {
            $document = (new Parser)->parseContent($pdfContents);
        } catch (Throwable $e) {
            if (str_contains(strtolower($e->getMessage()), 'secured')) {
                return [false, 0];
            }

            Log::warning('Local resume text extraction failed to parse the PDF.', [
                'exception' => get_class($e),
            ]);

            return [null, 0];
        }

        // The parse call above already did the expensive work in one
        // blocking step — if it alone ran past budget, don't compound it
        // by also walking every page; report nothing rather than a very
        // late, possibly resource-heavy result.
        if (microtime(true) - $start > self::MAX_EXTRACTION_SECONDS) {
            Log::warning('Local resume text extraction exceeded its time budget while parsing.');

            return [null, 0];
        }

        $pages = array_slice($document->getPages(), 0, self::MAX_PAGES);
        $text = '';

        foreach ($pages as $page) {
            if (microtime(true) - $start > self::MAX_EXTRACTION_SECONDS) {
                break;
            }

            try {
                $text .= $page->getText()."\n";
            } catch (Throwable) {
                continue; // One unreadable page shouldn't discard the rest.
            }

            if (mb_strlen($text) >= self::MAX_TEXT_LENGTH) {
                break;
            }
        }

        $text = trim($text);

        return [$text === '' ? null : $text, count($pages)];
    }

    private function detectYearsExperience(string $text): ?int
    {
        if (preg_match('/\b(\d{1,2})\s*\+?\s*years?\b(?:\s+of)?\s*(?:professional\s+|relevant\s+|work\s+)?experience\b/i', $text, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function detectEducationLevel(string $text): ?string
    {
        $lower = strtolower($text);

        foreach (self::EDUCATION_KEYWORDS as $level => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    return $level;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function matchSkills(string $text): array
    {
        $dictionary = $this->skillsDictionary();
        $matches = [];

        foreach ($dictionary as $skill) {
            // Bounded, single character-class quantifier — the skill name
            // itself is a fixed, literal string (preg_quote escapes any
            // regex metacharacters in it), so there's no ambiguity for the
            // engine to backtrack through.
            if (preg_match('/\b'.preg_quote($skill, '/').'\b/i', $text)) {
                $matches[] = $skill;
            }
        }

        return $matches;
    }

    /**
     * @return list<string>
     */
    private function skillsDictionary(): array
    {
        $fromJobs = JobPosting::query()
            ->whereNotNull('required_skills')
            ->pluck('required_skills')
            ->flatten()
            ->filter()
            ->map(fn ($skill) => (string) $skill)
            ->all();

        $seen = [];
        $dictionary = [];

        foreach ([...$fromJobs, ...self::BUILT_IN_SKILLS] as $skill) {
            $key = strtolower(trim($skill));

            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $dictionary[] = trim($skill);
        }

        return $dictionary;
    }
}
