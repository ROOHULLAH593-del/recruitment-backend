<?php

namespace App\Notifications;

use App\Enums\InterviewStatus;
use App\Models\Interview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Queued — see the identical note on ApplicationStatusChanged, which this
 * mirrors: a slow/hanging mail server must never block the HTTP response a
 * status change already succeeded at. Needs a queue worker running to
 * actually send (`php artisan queue:work`, or `composer run dev` locally).
 */
class InterviewScheduled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Interview $interview,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $jobTitle = $this->interview->application->job->title;
        $when = $this->interview->scheduled_at->format('l, F j, Y \a\t g:i A');
        $isReschedule = $this->interview->status === InterviewStatus::Rescheduled;

        $message = (new MailMessage)
            ->subject($isReschedule ? "Interview Rescheduled: {$jobTitle}" : "Interview Scheduled: {$jobTitle}")
            ->greeting("Hi {$notifiable->name},")
            ->line(
                $isReschedule
                    ? "Your interview for the **{$jobTitle}** position has been rescheduled."
                    : "Great news! An interview has been scheduled for your application to the **{$jobTitle}** position."
            )
            ->line("**Date & time:** {$when}")
            ->line("**Interviewer:** {$this->interview->interviewer->name}");

        if ($this->interview->notes) {
            $message->line("**Notes:** {$this->interview->notes}");
        }

        return $message
            ->action('View Interview Details', $this->interviewUrl())
            ->line('Please reach out if you have any questions or need to reschedule.');
    }

    /**
     * Links into the React SPA (not this API's own domain) — see the
     * identical note on ApplicationStatusChanged::applicationUrl().
     */
    private function interviewUrl(): string
    {
        return sprintf('%s/interviews/%d', rtrim(config('app.frontend_url'), '/'), $this->interview->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'interview_id' => $this->interview->id,
            'application_id' => $this->interview->application_id,
            'scheduled_at' => $this->interview->scheduled_at->toIso8601String(),
        ];
    }
}
