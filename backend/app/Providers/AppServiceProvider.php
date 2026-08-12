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

        $this->configureRateLimiting();
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
    }
}
