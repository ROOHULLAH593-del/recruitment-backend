<?php

namespace App\Notifications;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApplicationStatusChanged extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Application $application,
        public readonly ?ApplicationStatus $previousStatus = null,
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
        $jobTitle = $this->application->job->title;
        $statusLabel = $this->label($this->application->status);

        $message = (new MailMessage)
            ->subject("Update on your application for {$jobTitle}")
            ->greeting("Hi {$notifiable->name},");

        if ($this->previousStatus) {
            $message->line("Your application for the **{$jobTitle}** position has moved from **{$this->label($this->previousStatus)}** to **{$statusLabel}**.");
        } else {
            $message->line("Your application for the **{$jobTitle}** position is now **{$statusLabel}**.");
        }

        $message->line($this->statusMessage($this->application->status));

        if ($this->application->status === ApplicationStatus::Rejected && filled($this->application->rejection_reason)) {
            $message->line("**Feedback:** {$this->application->rejection_reason}");
        }

        return $message
            ->action('View Your Application', url("/applications/{$this->application->id}"))
            ->line('Thank you for your interest in joining our team.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'job_id' => $this->application->job_id,
            'status' => $this->application->status->value,
        ];
    }

    private function label(ApplicationStatus $status): string
    {
        return ucwords(str_replace('_', ' ', $status->value));
    }

    private function statusMessage(ApplicationStatus $status): string
    {
        return match ($status) {
            ApplicationStatus::Applied => 'Your application has been received and is under review.',
            ApplicationStatus::Shortlisted => "Great news — you've been shortlisted for this role. We'll be in touch soon with next steps.",
            ApplicationStatus::InterviewScheduled => "An interview has been scheduled for this role. You'll receive a separate email with the date and time.",
            ApplicationStatus::Interviewed => "Thank you for taking the time to interview with us. We're reviewing feedback and will follow up soon.",
            ApplicationStatus::Offered => 'Congratulations! Please check your email for details of the offer.',
            ApplicationStatus::Rejected => "After careful consideration, we've decided not to move forward with your application at this time. We appreciate the time you invested and encourage you to apply again in the future.",
            ApplicationStatus::Hired => 'Congratulations and welcome aboard! Our team will be in touch with onboarding details shortly.',
        };
    }
}
