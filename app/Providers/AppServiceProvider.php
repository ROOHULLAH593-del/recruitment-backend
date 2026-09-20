<?php

namespace App\Providers;

use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\JobPosting;
use App\Observers\ApplicationObserver;
use App\Observers\CandidateProfileObserver;
use App\Observers\JobPostingObserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Application::observe(ApplicationObserver::class);
        CandidateProfile::observe(CandidateProfileObserver::class);
        JobPosting::observe(JobPostingObserver::class);

        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        // This is an API-only app with no Blade routes, so the
        // notification's default URL (built from a `password.reset` named
        // route) would throw — point it at the React SPA's reset page
        // instead, in the shape ResetPasswordRequest expects.
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return sprintf(
                '%s/reset-password?token=%s&email=%s',
                rtrim(config('app.frontend_url'), '/'),
                $token,
                urlencode($notifiable->getEmailForPasswordReset()),
            );
        });
    }
}
