<?php

namespace App\Notifications;

use App\Enums\InterviewStatus;
use App\Models\Interview;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InterviewScheduled extends Notification
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
            ->action('View Interview Details', url("/interviews/{$this->interview->id}"))
            ->line('Please reach out if you have any questions or need to reschedule.');
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
