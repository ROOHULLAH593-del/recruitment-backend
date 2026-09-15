<?php

namespace App\Providers;

use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\JobPosting;
use App\Observers\ApplicationObserver;
use App\Observers\CandidateProfileObserver;
use App\Observers\JobPostingObserver;
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
    }
}
