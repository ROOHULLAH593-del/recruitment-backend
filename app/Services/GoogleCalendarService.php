<?php

namespace App\Services;

use App\Models\Interview;
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creates/updates/deletes calendar events for scheduled interviews.
 *
 * Known limitation: this uses a bare service account (no Google Workspace
 * Domain-Wide Delegation configured), and the Calendar API rejects any
 * attempt by such an account to add attendees or trigger email invites
 * ("Service accounts cannot invite attendees without Domain-Wide Delegation
 * of Authority"). Events are therefore created without an `attendees` list;
 * the candidate's and interviewer's names/emails are included in the event
 * description instead, so the information is still visible on the event.
 * If Domain-Wide Delegation is set up later, call $client->setSubject() with
 * an impersonated Workspace user's email and reintroduce EventAttendee
 * entries (plus 'sendUpdates' => 'all') in buildEventPayload()/the API calls
 * to get real invites.
 */
class GoogleCalendarService
{
    private const EVENT_DURATION_MINUTES = 30;

    private ?Calendar $service = null;

    private bool $clientInitializationFailed = false;

    /**
     * Create a calendar event for a newly scheduled interview and return its Google event ID.
     */
    public function createEvent(Interview $interview): ?string
    {
        $calendarId = $this->calendarId();
        $service = $calendarId ? $this->client() : null;

        if (! $service || ! $calendarId) {
            return null;
        }

        try {
            $event = $service->events->insert(
                $calendarId,
                new Event($this->buildEventPayload($interview)),
            );

            return $event->getId();
        } catch (Throwable $e) {
            Log::error('Failed to create Google Calendar event for interview.', [
                'interview_id' => $interview->id,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Update the calendar event for a rescheduled interview.
     */
    public function updateEvent(Interview $interview): void
    {
        if (! $interview->google_calendar_event_id) {
            return;
        }

        $calendarId = $this->calendarId();
        $service = $calendarId ? $this->client() : null;

        if (! $service || ! $calendarId) {
            return;
        }

        try {
            $service->events->update(
                $calendarId,
                $interview->google_calendar_event_id,
                new Event($this->buildEventPayload($interview)),
            );
        } catch (Throwable $e) {
            Log::error('Failed to update Google Calendar event for interview.', [
                'interview_id' => $interview->id,
                'event_id' => $interview->google_calendar_event_id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delete the calendar event for a cancelled interview.
     */
    public function deleteEvent(Interview $interview): void
    {
        if (! $interview->google_calendar_event_id) {
            return;
        }

        $calendarId = $this->calendarId();
        $service = $calendarId ? $this->client() : null;

        if (! $service || ! $calendarId) {
            return;
        }

        try {
            $service->events->delete($calendarId, $interview->google_calendar_event_id);
        } catch (Throwable $e) {
            Log::error('Failed to delete Google Calendar event for interview.', [
                'interview_id' => $interview->id,
                'event_id' => $interview->google_calendar_event_id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function calendarId(): ?string
    {
        return config('services.google_calendar.calendar_id') ?: null;
    }

    /**
     * Lazily build (and memoize) the authenticated Calendar service client.
     * Returns null — logging the reason — if credentials are missing or invalid.
     */
    private function client(): ?Calendar
    {
        if ($this->service) {
            return $this->service;
        }

        if ($this->clientInitializationFailed) {
            return null;
        }

        $credentialsPath = config('services.google_calendar.credentials_path');

        if (! $credentialsPath || ! is_file($credentialsPath)) {
            $this->clientInitializationFailed = true;

            Log::warning('Google Calendar credentials file not found; skipping calendar sync.', [
                'path' => $credentialsPath,
            ]);

            return null;
        }

        try {
            $client = new Client;
            $client->setAuthConfig($credentialsPath);
            $client->addScope(Calendar::CALENDAR_EVENTS);

            return $this->service = new Calendar($client);
        } catch (Throwable $e) {
            $this->clientInitializationFailed = true;

            Log::error('Failed to initialize the Google Calendar client.', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEventPayload(Interview $interview): array
    {
        $application = $interview->application;
        $job = $application?->job;
        $candidate = $application?->candidate;
        $interviewer = $interview->interviewer;

        $start = $interview->scheduled_at->copy();
        $end = $start->copy()->addMinutes(self::EVENT_DURATION_MINUTES);
        $timezone = config('app.timezone');

        // Attendees can't be invited from a bare service account (see class docblock),
        // so the participants are listed in the description instead.
        $description = "Interview for the {$job?->title} position.\n"
            ."Candidate: {$candidate?->name} ({$candidate?->email})\n"
            ."Interviewer: {$interviewer?->name} ({$interviewer?->email})";

        if ($interview->notes) {
            $description .= "\n\nNotes: {$interview->notes}";
        }

        return [
            'summary' => "Interview: {$candidate?->name} — {$job?->title}",
            'description' => $description,
            'start' => new EventDateTime(['dateTime' => $start->toRfc3339String(), 'timeZone' => $timezone]),
            'end' => new EventDateTime(['dateTime' => $end->toRfc3339String(), 'timeZone' => $timezone]),
        ];
    }
}
