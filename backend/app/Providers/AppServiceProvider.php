<?php

namespace App\Providers;

use App\Models\CbtAttempt;
use App\Models\CbtExam;
use App\Models\Student;
use App\Policies\CbtAttemptPolicy;
use App\Policies\CbtExamPolicy;
use App\Policies\StudentPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One tenant per request/job, resolved once. The global scope in
        // BelongsToTenant reads this instead of re-resolving the acting user's
        // profile on every model boot.
        $this->app->singleton(\App\Support\TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Student::class, StudentPolicy::class);
        Gate::policy(CbtExam::class, CbtExamPolicy::class);
        Gate::policy(CbtAttempt::class, CbtAttemptPolicy::class);

        // Keys and settings managed from the operator console, laid over the
        // config paths the services already read. Unset keys keep their
        // backend.env values.
        \App\Support\PlatformSettings::apply();

        $this->guardProductionConfig();
        $this->markPlatformCommandsUnscoped();
        $this->configureRateLimiting();
    }

    /**
     * Refuse to boot a production deployment with development settings.
     *
     * `APP_DEBUG=true` prints stack traces and environment values to anyone who
     * triggers an error, and `MAIL_MAILER=log` silently swallows every
     * password-reset and account-setup link. Both are the defaults in
     * .env.example, so both are easy to ship by accident.
     */
    private function guardProductionConfig(): void
    {
        if (! $this->app->environment('production') || $this->app->runningUnitTests()) {
            return;
        }

        $problems = [];

        if (config('app.debug')) {
            $problems[] = 'APP_DEBUG must be false';
        }

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $problems[] = 'MAIL_MAILER must be a real transport (not log/array)';
        }

        if ($problems !== []) {
            throw new \RuntimeException('Unsafe production configuration: '.implode('; ', $problems).'.');
        }
    }

    /**
     * Platform commands that work across every school by design: the
     * scheduled sweeps and reminders, and the database tools. They say so
     * here, once, instead of each relying on the tenant scope falling open.
     * Queue workers and tinker are deliberately absent.
     */
    private const PLATFORM_COMMANDS = [
        'cbt:expire-attempts', 'id-cards:sweep', 'exports:sweep', 'admissions:purge',
        'lms:remind', 'lms:sync-attendance', 'library:daily', 'marketing:prune-leads',
        'schoolpilot:generate-insights',
        'migrate', 'migrate:fresh', 'migrate:refresh', 'migrate:rollback', 'db:seed',
    ];

    private function markPlatformCommandsUnscoped(): void
    {
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Console\Events\CommandStarting::class,
            function ($event) {
                if (in_array($event->command, self::PLATFORM_COMMANDS, true)) {
                    app(\App\Support\TenantContext::class)->markUnscoped();
                }
            }
        );
    }

    private function configureRateLimiting(): void
    {
        // Credential stuffing guard. Keyed on email+IP so one attacker cycling
        // through passwords cannot lock out an unrelated user on a shared NAT,
        // which is common on Nigerian mobile networks.
        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->input('email');

            return [
                Limit::perMinute(5)->by(sha1($email . '|' . $request->ip())),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        /*
         * Password recovery. Tighter than login because the damage is
         * different: each accepted forgot-password can bill the school for an
         * SMS and put a mail in someone's inbox they did not ask for, and each
         * reset-password is a guess at a token.
         *
         * Keyed on email as well as IP so one address cannot be mail-bombed
         * from a botnet, and on IP so one host cannot walk a list of addresses.
         */
        RateLimiter::for('password-reset', function (Request $request) {
            $email = (string) $request->input('email');

            return [
                Limit::perMinute(3)->by(sha1('pwd|' . $email)),
                Limit::perMinute(10)->by($request->ip()),
            ];
        });

        // Gateway callbacks must not be throttled alongside ordinary API
        // traffic — a 429 during peak fee season silently drops a payment
        // confirmation. Kept generous but not unbounded.
        RateLimiter::for('webhooks', function (Request $request) {
            return Limit::perMinute(300)->by($request->ip());
        });

        // Marketing-site lead forms: a person sends one or two, a script sends
        // hundreds (Landing PRD §14.2).
        RateLimiter::for('marketing-leads', function (Request $request) {
            return [
                Limit::perMinute(5)->by('lead-min|'.$request->ip()),
                Limit::perDay(20)->by('lead-day|'.$request->ip()),
            ];
        });
    }
}
