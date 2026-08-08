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
        //
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

        // Gateway callbacks must not be throttled alongside ordinary API
        // traffic — a 429 during peak fee season silently drops a payment
        // confirmation. Kept generous but not unbounded.
        RateLimiter::for('webhooks', function (Request $request) {
            return Limit::perMinute(300)->by($request->ip());
        });
    }
}
