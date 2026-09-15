<?php

namespace Tests\Unit;

use App\Models\Interview;
use App\Services\GoogleCalendarService;
use Tests\TestCase;

class GoogleCalendarServiceTest extends TestCase
{
    private GoogleCalendarService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new GoogleCalendarService;
    }

    public function test_create_event_returns_null_when_calendar_id_is_not_configured(): void
    {
        config(['services.google_calendar.calendar_id' => null]);

        $this->assertNull($this->service->createEvent(new Interview));
    }

    public function test_create_event_returns_null_when_credentials_file_is_missing(): void
    {
        config([
            'services.google_calendar.calendar_id' => 'test-calendar-id@group.calendar.google.com',
            'services.google_calendar.credentials_path' => storage_path('app/private/does-not-exist.json'),
        ]);

        $this->assertNull($this->service->createEvent(new Interview));
    }

    public function test_update_event_does_nothing_when_interview_has_no_google_event_id(): void
    {
        config(['services.google_calendar.calendar_id' => null]);

        // No exception, no error: it should simply no-op before ever touching config/network.
        $this->service->updateEvent(new Interview(['google_calendar_event_id' => null]));
        $this->addToAssertionCount(1);
    }

    public function test_delete_event_does_nothing_when_interview_has_no_google_event_id(): void
    {
        config(['services.google_calendar.calendar_id' => null]);

        $this->service->deleteEvent(new Interview(['google_calendar_event_id' => null]));
        $this->addToAssertionCount(1);
    }

    public function test_update_event_does_nothing_when_calendar_id_is_not_configured(): void
    {
        config(['services.google_calendar.calendar_id' => null]);

        $interview = new Interview(['google_calendar_event_id' => 'some-real-looking-id']);

        $this->service->updateEvent($interview);
        $this->addToAssertionCount(1);
    }

    public function test_delete_event_does_nothing_when_calendar_id_is_not_configured(): void
    {
        config(['services.google_calendar.calendar_id' => null]);

        $interview = new Interview(['google_calendar_event_id' => 'some-real-looking-id']);

        $this->service->deleteEvent($interview);
        $this->addToAssertionCount(1);
    }
}
