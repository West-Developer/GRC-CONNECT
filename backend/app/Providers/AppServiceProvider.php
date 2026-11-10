<?php

namespace App\Providers;

use App\Domain\Academic\PrerequisiteEvaluator;
use App\Mail\Transports\ResendHttpTransport;
use App\Models\PersonalAccessToken;
use App\Policies\AcademicRecordPolicy;
use App\Policies\AttritionReportPolicy;
use App\Policies\ChedReportPolicy;
use App\Policies\DashboardPolicy;
use App\Policies\EligibleSubjectPolicy;
use App\Policies\FacultyMemberPolicy;
use App\Policies\GraduatePolicy;
use App\Policies\HonorsReportPolicy;
use App\Policies\ItControlPolicy;
use App\Policies\SectionPolicy;
use App\Policies\StuckEnrollmentPolicy;
use App\Policies\StudentProfilePolicy;
use App\Policies\UserAccountPolicy;
use App\Support\Auth\GoogleIdTokenVerifier;
use App\Support\Auth\JwksGoogleIdTokenVerifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // PrerequisiteEvaluator takes primitives, not a config array, so its
        // "policy unconfigured -> needs_verification" behavior is exercised
        // identically in unit tests and in the resolved application service.
        $this->app->bind(PrerequisiteEvaluator::class, fn (): PrerequisiteEvaluator => new PrerequisiteEvaluator(
            config('enrollment.grading.comparison'),
            config('enrollment.grading.special_marks', []),
        ));

        // The one seam GoogleLoginEndpointTest swaps for a fake — no test in
        // the suite contacts Google except JwksGoogleIdTokenVerifierTest,
        // which fakes the HTTP layer instead of this binding.
        $this->app->bind(GoogleIdTokenVerifier::class, JwksGoogleIdTokenVerifier::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Separate from Laravel's SDK-based `resend` driver: use the installed HTTP
        // client so Render Free can deliver mail without SMTP or another dependency.
        Mail::extend('resend_http', fn (array $config): ResendHttpTransport => new ResendHttpTransport(
            (string) config('services.resend.key', ''),
            (int) ($config['timeout'] ?? 15),
        ));

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        $this->preventNPlusOneQueries();

        // A freshman's emailed live-queue link (ADR 0044) is polled every few seconds
        // and shared by many people on one campus network, so the limit is per link,
        // with only a generous per-address ceiling against flooding.
        RateLimiter::for('public-queue-view', fn (Request $request): array => [
            Limit::perMinute(40)->by('link:'.hash('sha256', (string) $request->route('token'))),
            Limit::perMinute(3000)->by('ip:'.$request->ip()),
        ]);

        Gate::define('view-faculty-directory', [FacultyMemberPolicy::class, 'viewAny']);
        Gate::define('view-it-control-account-browser', [ItControlPolicy::class, 'viewAccountBrowser']);
        Gate::define('manage-it-control-account', [ItControlPolicy::class, 'manageAccount']);
        Gate::define('view-faculty-profile', [FacultyMemberPolicy::class, 'viewProfile']);
        Gate::define('update-faculty-workforce-profile', [FacultyMemberPolicy::class, 'updateWorkforceProfile']);
        Gate::define('search-cashier-students', [StudentProfilePolicy::class, 'searchForCashier']);
        Gate::define('view-eligible-subjects', [EligibleSubjectPolicy::class, 'viewAny']);
        Gate::define('view-enrollment-summary', [DashboardPolicy::class, 'viewEnrollmentSummary']);
        Gate::define('view-enrollment-status', [DashboardPolicy::class, 'viewEnrollmentStatus']);
        Gate::define('view-enrollment-movements', [DashboardPolicy::class, 'viewEnrollmentMovements']);
        Gate::define('record-program-shift', [DashboardPolicy::class, 'recordProgramShift']);
        Gate::define('view-enrollment-status-students', [DashboardPolicy::class, 'viewEnrollmentStatusStudents']);
        Gate::define('view-institution-summary', [DashboardPolicy::class, 'viewInstitutionSummary']);
        Gate::define('view-policy-settings', [DashboardPolicy::class, 'viewPolicySettings']);
        Gate::define('view-analytics', [DashboardPolicy::class, 'viewAnalytics']);
        Gate::define('view-stuck-enrollments', [StuckEnrollmentPolicy::class, 'viewAny']);
        Gate::define('view-academic-record', [AcademicRecordPolicy::class, 'view']);
        Gate::define('search-academic-records', [AcademicRecordPolicy::class, 'viewAny']);
        Gate::define('view-attrition-report', [AttritionReportPolicy::class, 'view']);
        Gate::define('view-honors-report', [HonorsReportPolicy::class, 'view']);
        Gate::define('view-graduates', [GraduatePolicy::class, 'viewAny']);
        Gate::define('view-ched-reports', [ChedReportPolicy::class, 'view']);
        Gate::define('view-section-grade-submission', [SectionPolicy::class, 'viewGradeSubmission']);
        Gate::define('view-any-user-account', [UserAccountPolicy::class, 'viewAny']);
        Gate::define('invite-user-account', [UserAccountPolicy::class, 'invite']);
        Gate::define('change-user-account-role', [UserAccountPolicy::class, 'changeRole']);
        Gate::define('update-user-account-status', [UserAccountPolicy::class, 'updateStatus']);
        Gate::define('resend-user-account-invitation', [UserAccountPolicy::class, 'resendInvitation']);
        Gate::define('reset-user-account-password', [UserAccountPolicy::class, 'resetPassword']);
        Gate::define('revoke-user-account-sessions', [UserAccountPolicy::class, 'revokeSessions']);
        Gate::define('delete-user-account', [UserAccountPolicy::class, 'delete']);
    }

    /**
     * PRD §8.1: Resources read relations directly (no `whenLoaded`), so every
     * list query must eager-load exactly what its Resource renders. Outside
     * production, a lazy load on a model hydrated from a multi-row query is
     * an N+1 bug: tests fail on it, local development logs it. Production is
     * never affected.
     */
    private function preventNPlusOneQueries(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        $seenViolations = [];

        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) use (&$seenViolations): void {
            if ($this->app->environment('local') || config('app.lazy_loading_violations') === 'log') {
                $key = $model::class.'::'.$relation;
                if (! isset($seenViolations[$key])) {
                    $seenViolations[$key] = true;
                    Log::warning('N+1 lazy load', ['model' => $model::class, 'relation' => $relation]);
                }

                return;
            }

            throw new LazyLoadingViolationException($model, $relation);
        });
    }
}
