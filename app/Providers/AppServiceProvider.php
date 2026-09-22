<?php

namespace App\Providers;

use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\JobPosting;
use App\Observers\ApplicationObserver;
use App\Observers\CandidateProfileObserver;
use App\Observers\JobPostingObserver;
use App\Services\LaravelBackedHttpClient;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
        // Where TLS is terminated by the hosting platform's proxy, PHP itself
        // only ever sees plain HTTP, so every generated URL (pagination
        // links, signed URLs, ...) would come out as http:// and be blocked
        // as mixed content by an HTTPS frontend. APP_URL is the single source
        // of truth for whether a deployment is served over HTTPS, rather than
        // assuming every production environment is — a host without working
        // HTTPS (APP_URL=http://...) must keep generating http:// links.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        Application::observe(ApplicationObserver::class);
        CandidateProfile::observe(CandidateProfileObserver::class);
        JobPosting::observe(JobPostingObserver::class);

        // Only built when MAIL_MAILER=brevo, so local development (smtp)
        // never touches this and needs no Brevo key.
        Mail::extend('brevo', function () {
            $key = config('services.brevo.key');

            if (blank($key)) {
                throw new InvalidArgumentException('BREVO_API_KEY must be set to use the brevo mailer.');
            }

            return (new BrevoTransportFactory(null, $this->brevoHttpClient()))->create(new Dsn('brevo+api', 'default', $key));
        });

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

    /**
     * The HTTP client under the Brevo mail transport.
     *
     * Symfony's default client needs curl_multi_exec(), which some shared
     * hosts (InfinityFree) disable, breaking every send. There the transport
     * goes through Laravel's HTTP client instead (Guzzle, which falls back to
     * plain curl_exec). Returning null keeps Symfony's default everywhere else,
     * so local development and other hosts behave exactly as before.
     *
     * BREVO_HTTP_CLIENT: "auto" (default), "laravel" to force the fallback,
     * or "symfony" to force Symfony's client.
     */
    private function brevoHttpClient(): ?HttpClientInterface
    {
        $useLaravelClient = match (config('services.brevo.http_client')) {
            'laravel' => true,
            'symfony' => false,
            default => ! function_exists('curl_multi_exec'),
        };

        return $useLaravelClient ? new LaravelBackedHttpClient : null;
    }
}
