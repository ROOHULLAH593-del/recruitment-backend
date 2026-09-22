<?php

namespace App\Notifications;

use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OfferSent extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Application $application,
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
        $job = $this->application->job;

        $message = (new MailMessage)
            ->subject("Job Offer: {$job->title}")
            ->greeting("Congratulations, {$notifiable->name}!")
            ->line("We are delighted to offer you the position of **{$job->title}**".($job->department ? " on our {$job->department} team" : '').'.');

        $salaryRange = $this->formatSalaryRange($job->salary_min, $job->salary_max);

        if ($salaryRange) {
            $message->line("**Compensation:** {$salaryRange}");
        }

        if ($job->location) {
            $message->line("**Location:** {$job->location}");
        }

        return $message
            ->line("This is an exciting moment for us, and we hope you're just as excited to join the team.")
            ->action('Review Your Offer', $this->applicationUrl())
            ->line('Please let us know if you have any questions — we look forward to hearing from you soon.');
    }

    /**
     * Links into the React SPA — see the identical note on
     * ApplicationStatusChanged::applicationUrl(), which this mirrors.
     */
    private function applicationUrl(): string
    {
        return sprintf('%s/applications/%d', rtrim(config('app.frontend_url'), '/'), $this->application->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'job_id' => $this->application->job_id,
        ];
    }

    private function formatSalaryRange(?string $min, ?string $max): ?string
    {
        if ($min && $max) {
            return 'Rs '.number_format((float) $min).' - Rs '.number_format((float) $max);
        }

        if ($min) {
            return 'From Rs '.number_format((float) $min);
        }

        if ($max) {
            return 'Up to Rs '.number_format((float) $max);
        }

        return null;
    }
}
