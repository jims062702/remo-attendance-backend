<?php

declare(strict_types=1);

namespace App\Providers;

use App\Mail\Transport\BrevoTransport;
use App\Services\ActivityLogger;
use App\Services\AttendanceService;
use App\Services\DailyFlowService;
use App\Services\ReportService;
use App\Services\TaskService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The services hold no per-request state, and API Resources resolve
        // AttendanceService once per item when serialising a collection --
        // singletons keep that to a single instantiation per request.
        $this->app->singleton(ActivityLogger::class);
        $this->app->singleton(AttendanceService::class);
        $this->app->singleton(TaskService::class);
        $this->app->singleton(ReportService::class);
        $this->app->singleton(DailyFlowService::class);
    }

    public function boot(): void
    {
        // Fail loudly on a lazy-loaded relation in development rather than
        // shipping an N+1 that only shows up under production data volumes.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Silently discarding an attribute that has no place in $fillable
        // hides real bugs; in development, raise instead.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        $this->configureRateLimiting();
        $this->registerBrevoMailer();
    }

    /**
     * Register the Brevo transport under the "brevo" mailer name.
     *
     * Laravel ships transports for Resend, Postmark, SES and Mailgun but not
     * for Brevo, so the driver has to be taught. Everything else -- queueing,
     * the Mailable API, Mail::fake() in tests -- is unchanged by this.
     */
    private function registerBrevoMailer(): void
    {
        Mail::extend('brevo', function (array $config = []): BrevoTransport {
            $key = $config['key'] ?? config('services.brevo.key');

            if (blank($key)) {
                // Better than an opaque 401 from the API on the first send.
                throw new InvalidArgumentException(
                    'The Brevo mailer is selected but BREVO_KEY is not set.',
                );
            }

            return new BrevoTransport((string) $key);
        });
    }

    /**
     * Login is limited by email+IP so that one attacker cannot lock out an
     * entire office behind a shared NAT address, while still capping attempts
     * against any single account.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        // Clock events are inherently low frequency; a burst means a stuck
        // button or a script, neither of which should reach the database.
        RateLimiter::for('clock', fn (Request $request) => Limit::perMinute(10)
            ->by($request->user()?->id ?: $request->ip()));

        // Report generation and Excel export are expensive.
        RateLimiter::for('reports', fn (Request $request) => Limit::perMinute(20)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
