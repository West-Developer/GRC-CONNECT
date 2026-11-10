<?php

namespace Tests\Feature\Api\V1;

use App\Http\Middleware\EnsureQueueKioskUsesDeviceSurface;
use App\Http\Middleware\EnsureStudentQueueClaimUsesKiosk;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ApiSurfaceTest extends TestCase
{
    public function test_the_api_exposes_only_the_documented_routes(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->map(
                static fn ($route): string => implode('|', $route->methods())
                    .' '.$route->uri(),
            )
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'DELETE api/v1/academic-terms/{academicTerm}/faculty-load-overrides/{professor}',
            'DELETE api/v1/enrollments/{enrollment}/scholarship-discount',
            'DELETE api/v1/faculty-availabilities/{facultyAvailability}',
            'DELETE api/v1/faculty-curriculum-subject-preferences/{facultyCurriculumPreference}',
            'DELETE api/v1/faculty-specializations/{facultySpecialization}',
            'DELETE api/v1/faculty-subject-preferences/{facultySubjectPreference}',
            'DELETE api/v1/it-control/accounts/{user}',
            'DELETE api/v1/student-profile-change-requests/{studentProfileChangeRequest}',
            'DELETE api/v1/subject-waivers/{waiver}',
            'DELETE api/v1/super-admin/acting-context',
            'DELETE api/v1/super-admin/users/{user}',
            'DELETE api/v1/super-admin/users/{user}/sessions',
            'DELETE api/v1/tor-documents/{torDocument}',
            'DELETE api/v1/transferee-credits/{transfereeCredit}',
            'GET|HEAD api/v1/academic-grades',
            'GET|HEAD api/v1/academic-grades/approval-professors',
            'GET|HEAD api/v1/academic-grades/approval-sections',
            'GET|HEAD api/v1/academic-record',
            'GET|HEAD api/v1/academic-record/students',
            'GET|HEAD api/v1/academic-term-section-plans',
            'GET|HEAD api/v1/academic-term-workflows',
            'GET|HEAD api/v1/academic-terms',
            'GET|HEAD api/v1/academic-terms/{academicTerm}/enrollment-windows',
            'GET|HEAD api/v1/academic-terms/{academicTerm}/faculty-load-report',
            'GET|HEAD api/v1/academic-terms/{academicTerm}/lecture-lab-adjacency',
            'GET|HEAD api/v1/academic-terms/{academicTerm}/schedule-generation-runs/latest',
            'GET|HEAD api/v1/admission-requirement-types',
            'GET|HEAD api/v1/analytics/attrition',
            'GET|HEAD api/v1/analytics/enrollment-movements',
            'GET|HEAD api/v1/audit-logs',
            'GET|HEAD api/v1/audit-logs/actors',
            'GET|HEAD api/v1/auth/me',
            'GET|HEAD api/v1/cashier-payment-candidates',
            'GET|HEAD api/v1/cashier-student-lookup',
            'GET|HEAD api/v1/cashier-transactions',
            'GET|HEAD api/v1/ched-reports/{report}',
            'GET|HEAD api/v1/ched-reports/{report}/export',
            'GET|HEAD api/v1/class-rosters',
            'GET|HEAD api/v1/curricula',
            'GET|HEAD api/v1/curricula/{curriculum}/migration-preview',
            'GET|HEAD api/v1/dashboards/enrollment-status',
            'GET|HEAD api/v1/dashboards/enrollment-status/sections',
            'GET|HEAD api/v1/dashboards/enrollment-status/students',
            'GET|HEAD api/v1/dashboards/enrollment-status/students/{studentProfile}',
            'GET|HEAD api/v1/dashboards/enrollment-summary',
            'GET|HEAD api/v1/dashboards/institution-summary',
            'GET|HEAD api/v1/dashboards/policy-settings',
            'GET|HEAD api/v1/dashboards/program-chair-analytics-summary',
            'GET|HEAD api/v1/eligible-subjects',
            'GET|HEAD api/v1/enrollment-blocks',
            'GET|HEAD api/v1/enrollment-change-requests',
            'GET|HEAD api/v1/enrollment-documents',
            'GET|HEAD api/v1/enrollment-documents/{enrollmentDocument}',
            'GET|HEAD api/v1/enrollment-documents/{enrollmentDocument}/pdf',
            'GET|HEAD api/v1/enrollments',
            'GET|HEAD api/v1/enrollments/{enrollment}/cor-preview',
            'GET|HEAD api/v1/enrollments/{enrollment}/payment-quote',
            'GET|HEAD api/v1/faculty-availabilities',
            'GET|HEAD api/v1/faculty-curriculum-subject-preferences',
            'GET|HEAD api/v1/faculty-invitations',
            'GET|HEAD api/v1/faculty-members',
            'GET|HEAD api/v1/faculty-members/{professor}/profile',
            'GET|HEAD api/v1/faculty-preference-catalog',
            'GET|HEAD api/v1/faculty-specializations',
            'GET|HEAD api/v1/faculty-subject-preferences',
            'GET|HEAD api/v1/faculty-teaching-history',
            'GET|HEAD api/v1/fee-schedules',
            'GET|HEAD api/v1/grade-slip',
            'GET|HEAD api/v1/graduates',
            'GET|HEAD api/v1/health',
            'GET|HEAD api/v1/it-control/faculty',
            'GET|HEAD api/v1/it-control/students',
            'GET|HEAD api/v1/me/admission-requirements',
            'GET|HEAD api/v1/me/statement-of-account',
            'GET|HEAD api/v1/me/statement-of-account/pdf',
            'GET|HEAD api/v1/no-credit-declaration',
            'GET|HEAD api/v1/no-credit-declarations',
            'GET|HEAD api/v1/notifications',
            'GET|HEAD api/v1/payment-discount-settings',
            'GET|HEAD api/v1/payments',
            'GET|HEAD api/v1/privacy-notice',
            'GET|HEAD api/v1/program-chair/review-counts',
            'GET|HEAD api/v1/programs',
            'GET|HEAD api/v1/programs/{program}/current-curriculum-subjects',
            'GET|HEAD api/v1/prospectus',
            'GET|HEAD api/v1/public/queue-view/{token}',
            'GET|HEAD api/v1/queue-cycle',
            'GET|HEAD api/v1/queue-kiosk-credential',
            'GET|HEAD api/v1/queue-status',
            'GET|HEAD api/v1/queue-tickets',
            'GET|HEAD api/v1/reports/honors',
            'GET|HEAD api/v1/room-occupancy',
            'GET|HEAD api/v1/room-occupancy-summary',
            'GET|HEAD api/v1/room-options',
            'GET|HEAD api/v1/schedule-generation-runs/{scheduleGenerationRun}',
            'GET|HEAD api/v1/schedule-proposals',
            'GET|HEAD api/v1/schedule-proposals/{scheduleProposal}/sections',
            'GET|HEAD api/v1/section-change-requests',
            'GET|HEAD api/v1/sections',
            'GET|HEAD api/v1/sections/grade-submission',
            'GET|HEAD api/v1/sections/{section}/grades',
            'GET|HEAD api/v1/staff-invitations',
            'GET|HEAD api/v1/stuck-enrollments',
            'GET|HEAD api/v1/student-account',
            'GET|HEAD api/v1/student-profile',
            'GET|HEAD api/v1/student-profile-change-requests',
            'GET|HEAD api/v1/student-profiles',
            'GET|HEAD api/v1/student-profiles/next-student-number',
            'GET|HEAD api/v1/student-profiles/{studentProfile}',
            'GET|HEAD api/v1/student-profiles/{studentProfile}/admission-requirements',
            'GET|HEAD api/v1/student-profiles/{studentProfile}/freshman-payment-preview',
            'GET|HEAD api/v1/student-profiles/{studentProfile}/transferee-credits/suggestions',
            'GET|HEAD api/v1/student-schedule-preferences',
            'GET|HEAD api/v1/students',
            'GET|HEAD api/v1/students/{studentProfile}/registrar-profile',
            'GET|HEAD api/v1/students/{studentProfile}/subject-waivers',
            'GET|HEAD api/v1/students/{studentProfile}/subject-withdrawals',
            'GET|HEAD api/v1/students/{student}/account',
            'GET|HEAD api/v1/students/{student}/statement-of-account',
            'GET|HEAD api/v1/students/{student}/statement-of-account/pdf',
            'GET|HEAD api/v1/subject-offerings',
            'GET|HEAD api/v1/subjects',
            'GET|HEAD api/v1/super-admin/users',
            'GET|HEAD api/v1/tor-documents',
            'GET|HEAD api/v1/tor-documents/{torDocument}/file',
            'GET|HEAD api/v1/transferee-credits',
            'GET|HEAD api/v1/transferee-credits/{transfereeCredit}/suggestions',
            'GET|HEAD api/v1/withdrawal-requests',
            'PATCH api/v1/academic-grades/{academicGrade}',
            'PATCH api/v1/academic-term-workflows/{workflow}',
            'PATCH api/v1/academic-terms/{academicTerm}',
            'PATCH api/v1/academic-terms/{academicTerm}/draft-identity',
            'PATCH api/v1/academic-terms/{academicTerm}/enrollment-schedule',
            'PATCH api/v1/academic-terms/{academicTerm}/section-plan',
            'PATCH api/v1/curricula/{curriculum}',
            'PATCH api/v1/curricula/{curriculum}/transition',
            'PATCH api/v1/enrollment-change-requests/{enrollmentChangeRequest}',
            'PATCH api/v1/enrollments/{enrollment}',
            'PATCH api/v1/enrollments/{enrollment}/assessment',
            'PATCH api/v1/enrollments/{enrollment}/subjects',
            'PATCH api/v1/faculty-availabilities/{facultyAvailability}',
            'PATCH api/v1/faculty-curriculum-subject-preferences/{facultyCurriculumPreference}',
            'PATCH api/v1/faculty-members/{facultyMember}/workforce-profile',
            'PATCH api/v1/faculty-specializations/{facultySpecialization}',
            'PATCH api/v1/faculty-specializations/{facultySpecialization}/proficiency',
            'PATCH api/v1/faculty-subject-preferences/{facultySubjectPreference}',
            'PATCH api/v1/it-control/accounts/{user}',
            'PATCH api/v1/notifications/read-all',
            'PATCH api/v1/notifications/{notification}/read',
            'PATCH api/v1/queue-tickets/{queueTicket}',
            'PATCH api/v1/schedule-proposals/{scheduleProposal}',
            'PATCH api/v1/section-change-requests/{sectionChangeRequest}',
            'PATCH api/v1/sections/{section}',
            'PATCH api/v1/student-profile-change-requests/{studentProfileChangeRequest}',
            'PATCH api/v1/student-profile-change-requests/{studentProfileChangeRequest}/decision',
            'PATCH api/v1/student-profiles/{studentProfile}',
            'PATCH api/v1/super-admin/users/{user}/role',
            'PATCH api/v1/super-admin/users/{user}/status',
            'PATCH api/v1/transferee-credits/{transfereeCredit}',
            'PATCH api/v1/withdrawal-requests/{withdrawalRequest}',
            'POST api/v1/academic-grades',
            'POST api/v1/academic-grades/lock-all',
            'POST api/v1/academic-terms',
            'POST api/v1/academic-terms/{academicTerm}/archive-and-create-next',
            'POST api/v1/academic-terms/{academicTerm}/schedule-generation-runs',
            'POST api/v1/academic-terms/{academicTerm}/section-plan/auto-assign',
            'POST api/v1/academic-terms/{academicTerm}/section-plan/release',
            'POST api/v1/academic-terms/{academicTerm}/section-plan/submit',
            'POST api/v1/admission-requirement-types',
            'POST api/v1/auth/account-setup',
            'POST api/v1/auth/faculty-account-setup',
            'POST api/v1/auth/forgot-password',
            'POST api/v1/auth/google',
            'POST api/v1/auth/login',
            'POST api/v1/auth/login/resend-otp',
            'POST api/v1/auth/login/verify-otp',
            'POST api/v1/auth/logout',
            'POST api/v1/auth/privacy-notice/accept',
            'POST api/v1/auth/resend-student-account-setup',
            'POST api/v1/auth/reset-password',
            'POST api/v1/auth/staff-account-setup',
            'POST api/v1/curricula',
            'POST api/v1/curricula/{curriculum}/migrations',
            'POST api/v1/curricula/{curriculum}/subject-placements',
            'POST api/v1/enrollments',
            'POST api/v1/enrollments/{enrollment}/change-requests',
            'POST api/v1/enrollments/{enrollment}/payment',
            'POST api/v1/enrollments/{enrollment}/withdraw',
            'POST api/v1/faculty-availabilities',
            'POST api/v1/faculty-curriculum-subject-preferences',
            'POST api/v1/faculty-invitations',
            'POST api/v1/faculty-invitations/{user}/resend',
            'POST api/v1/faculty-specializations',
            'POST api/v1/faculty-subject-preferences',
            'POST api/v1/it-control/accounts/{user}/archive',
            'POST api/v1/it-control/accounts/{user}/password',
            'POST api/v1/it-control/accounts/{user}/restore',
            'POST api/v1/no-credit-declaration',
            'POST api/v1/no-credit-declarations/{noCreditDeclaration}/confirm',
            'POST api/v1/program-shifts',
            'POST api/v1/queue-cycle/cut-off',
            'POST api/v1/queue-cycle/resume',
            'POST api/v1/queue-tickets',
            'POST api/v1/schedule-proposals',
            'POST api/v1/sections',
            'POST api/v1/sections/{section}/change-requests',
            'POST api/v1/sections/{section}/grades',
            'POST api/v1/sections/{section}/grades/submit',
            'POST api/v1/staff-invitations',
            'POST api/v1/staff-invitations/{user}/resend',
            'POST api/v1/student-profile-change-requests',
            'POST api/v1/student-profiles',
            'POST api/v1/student-profiles/{studentProfile}/account-setup-invitations',
            'POST api/v1/student-profiles/{studentProfile}/freshman-payment',
            'POST api/v1/student-profiles/{studentProfile}/queue-link-resends',
            'POST api/v1/student-profiles/{studentProfile}/transferee-credits/approve-all',
            'POST api/v1/student-profiles/{studentProfile}/transferee-credits/submit-to-registrar-head',
            'POST api/v1/students/{studentProfile}/subject-waivers',
            'POST api/v1/students/{studentProfile}/subject-withdrawals',
            'POST api/v1/students/{student}/account-payments',
            'POST api/v1/subject-offerings',
            'POST api/v1/super-admin/users/invite',
            'POST api/v1/super-admin/users/{user}/password-reset',
            'POST api/v1/super-admin/users/{user}/setup-invitation',
            'POST api/v1/tor-documents',
            'POST api/v1/transferee-credits',
            'POST api/v1/transferee-credits/submit',
            'PUT api/v1/academic-terms/{academicTerm}/faculty-load-limits/{employmentType}',
            'PUT api/v1/academic-terms/{academicTerm}/faculty-load-overrides/{professor}',
            'PUT api/v1/academic-terms/{academicTerm}/faculty-load-threshold',
            'PUT api/v1/curricula/{curriculum}/max-units',
            'PUT api/v1/enrollments/{enrollment}/scholarship-discount',
            'PUT api/v1/fee-schedules',
            'PUT api/v1/payment-discount-settings',
            'PUT api/v1/queue-kiosk-credential',
            'PUT api/v1/sections/{section}/professor',
            'PUT api/v1/student-profiles/{studentProfile}/admission-requirements/{requirementType}',
            'PUT api/v1/student-schedule-preferences',
            'PUT api/v1/super-admin/acting-context',
        ], $routes);
    }

    public function test_the_authenticated_routes_are_guarded(): void
    {
        $guarded = [
            'api.v1.auth.logout',
            'api.v1.auth.me',
            'api.v1.auth.privacy-notice.accept',
            'api.v1.cashier-payment-candidates.show',
            'api.v1.cashier-transactions.index',
            'api.v1.analytics.attrition',
            'api.v1.audit-logs.actors',
            'api.v1.audit-logs.index',
            'api.v1.ched-reports.show',
            'api.v1.ched-reports.export',
            'api.v1.class-rosters.index',
            'api.v1.notifications.index',
            'api.v1.notifications.read',
            'api.v1.programs',
            'api.v1.academic-terms.index',
            'api.v1.academic-terms.update',
            'api.v1.academic-terms.draft-identity.update',
            'api.v1.academic-term-workflows.index',
            'api.v1.academic-term-workflows.update',
            'api.v1.academic-terms.store',
            'api.v1.subjects',
            'api.v1.curricula.index',
            'api.v1.curricula.store',
            'api.v1.curricula.update',
            'api.v1.curricula.migrations.preview',
            'api.v1.curricula.migrations.store',
            'api.v1.subject-offerings.index',
            'api.v1.subject-offerings.store',
            'api.v1.eligible-subjects.index',
            'api.v1.enrollment-blocks.index',
            'api.v1.academic-terms.archive-and-create-next',
            'api.v1.enrollments.index',
            'api.v1.enrollments.store',
            'api.v1.enrollments.update',
            'api.v1.enrollments.subjects.update',
            'api.v1.enrollments.assessment.update',
            'api.v1.enrollments.payment',
            'api.v1.enrollments.withdraw',
            'api.v1.withdrawal-requests.index',
            'api.v1.withdrawal-requests.update',
            'api.v1.enrollments.change-requests.store',
            'api.v1.enrollment-change-requests.index',
            'api.v1.enrollment-change-requests.update',
            'api.v1.transferee-credits.index',
            'api.v1.transferee-credits.store',
            'api.v1.transferee-credits.update',
            'api.v1.transferee-credits.suggestions',
            'api.v1.transferee-credits.submit',
            'api.v1.transferee-credits.destroy',
            'api.v1.no-credit-declaration.show',
            'api.v1.no-credit-declaration.store',
            'api.v1.no-credit-declarations.index',
            'api.v1.no-credit-declarations.confirm',
            'api.v1.program-chair.review-counts',
            'api.v1.student-profiles.transferee-credits.suggestions',
            'api.v1.student-profiles.transferee-credits.submit-to-registrar-head',
            'api.v1.student-profiles.transferee-credits.approve-all',
            'api.v1.tor-documents.index',
            'api.v1.tor-documents.store',
            'api.v1.tor-documents.file',
            'api.v1.tor-documents.destroy',
            'api.v1.enrollment-documents.index',
            'api.v1.enrollment-documents.show',
            'api.v1.payments.index',
            'api.v1.academic-grades.index',
            'api.v1.academic-grades.approval-professors',
            'api.v1.academic-grades.approval-sections',
            'api.v1.academic-grades.store',
            'api.v1.academic-grades.update',
            'api.v1.prospectus.show',
            'api.v1.grade-slip.show',
            'api.v1.reports.honors',
            'api.v1.it-control.accounts.archive',
            'api.v1.it-control.accounts.destroy',
            'api.v1.it-control.accounts.password',
            'api.v1.it-control.accounts.restore',
            'api.v1.it-control.accounts.update',
            'api.v1.it-control.faculty.index',
            'api.v1.it-control.students.index',
            'api.v1.queue-tickets.index',
            'api.v1.queue-tickets.store',
            'api.v1.queue-status.show',
            'api.v1.queue-kiosk-credential.show',
            'api.v1.queue-kiosk-credential.update',
            'api.v1.queue-tickets.update',
            'api.v1.queue-cycle.show',
            'api.v1.queue-cycle.cut-off',
            'api.v1.queue-cycle.resume',
            'api.v1.faculty-availabilities.index',
            'api.v1.faculty-members.index',
            'api.v1.faculty-availabilities.store',
            'api.v1.faculty-availabilities.update',
            'api.v1.faculty-availabilities.destroy',
            'api.v1.faculty-specializations.index',
            'api.v1.faculty-specializations.store',
            'api.v1.faculty-specializations.update-proficiency',
            'api.v1.faculty-specializations.destroy',
            'api.v1.faculty-subject-preferences.index',
            'api.v1.faculty-subject-preferences.store',
            'api.v1.faculty-subject-preferences.update',
            'api.v1.faculty-subject-preferences.destroy',
            'api.v1.section-change-requests.index',
            'api.v1.section-change-requests.update',
            'api.v1.sections.change-requests.store',
            'api.v1.sections.index',
            'api.v1.sections.grade-submission.index',
            'api.v1.sections.grades.show',
            'api.v1.sections.grades.store',
            'api.v1.sections.grades.submit',
            'api.v1.sections.store',
            'api.v1.sections.update',
            'api.v1.schedule-proposals.index',
            'api.v1.schedule-proposals.store',
            'api.v1.schedule-proposals.update',
            'api.v1.student-profile.show',
            'api.v1.student-account.show-own',
            'api.v1.students.index',
            'api.v1.students.account.show',
            'api.v1.students.account-payments.store',
            'api.v1.students.registrar-profile.show',
            'api.v1.students.subject-waivers.index',
            'api.v1.students.subject-waivers.store',
            'api.v1.students.subject-withdrawals.index',
            'api.v1.students.subject-withdrawals.store',
            'api.v1.subject-waivers.destroy',
            'api.v1.student-profile-change-requests.index',
            'api.v1.student-profile-change-requests.store',
            'api.v1.student-profile-change-requests.update',
            'api.v1.student-profile-change-requests.destroy',
            'api.v1.student-profile-change-requests.decision.update',
            'api.v1.student-profiles.index',
            'api.v1.student-profiles.store',
            'api.v1.student-profiles.show',
            'api.v1.student-profiles.update',
            'api.v1.student-profiles.account-setup-invitations.store',
            'api.v1.student-profiles.queue-link-resends.store',
            'api.v1.student-profiles.freshman-payment-preview.show',
            'api.v1.student-profiles.freshman-payment.store',
            'api.v1.student-profiles.admission-requirements.show',
            'api.v1.student-profiles.admission-requirements.update',
            'api.v1.admission-requirement-types.index',
            'api.v1.admission-requirement-types.store',
            'api.v1.me.admission-requirements.show',
            'api.v1.student-schedule-preferences.show',
            'api.v1.student-schedule-preferences.update',
            'api.v1.dashboards.enrollment-summary',
            'api.v1.dashboards.institution-summary',
            'api.v1.dashboards.policy-settings',
            'api.v1.stuck-enrollments.index',
        ];

        foreach ($guarded as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Missing route {$name}.");
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        }
    }

    public function test_queue_kiosk_device_surface_middleware_protects_both_authenticated_route_groups(): void
    {
        foreach (['api.v1.auth.me', 'api.v1.auth.logout', 'api.v1.programs'] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Missing route {$name}.");
            $this->assertContains(EnsureQueueKioskUsesDeviceSurface::class, $route->gatherMiddleware());
        }
    }

    public function test_queue_ticket_claim_has_one_student_kiosk_proof_route_without_a_blanket_role_gate(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => $route->methods() === ['POST'] && $route->uri() === 'api/v1/queue-tickets')
            ->values();

        $this->assertCount(1, $routes);

        $middleware = $routes->sole()->gatherMiddleware();
        $this->assertContains(EnsureStudentQueueClaimUsesKiosk::class, $middleware);
        $this->assertEmpty(array_filter($middleware, static fn (string $entry): bool => str_starts_with($entry, 'role:')));
    }

    public function test_curriculum_writes_are_gated_to_the_program_chair_role(): void
    {
        $gated = [
            'api.v1.curricula.store',
            'api.v1.curricula.update',
            'api.v1.curricula.migrations.preview',
            'api.v1.curricula.migrations.store',
        ];

        foreach ($gated as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Missing route {$name}.");
            $this->assertContains('role:program_chair', $route->gatherMiddleware());
        }

        $readRoute = Route::getRoutes()->getByName('api.v1.curricula.index');
        $this->assertNotNull($readRoute);
        $this->assertNotContains('role:program_chair', $readRoute->gatherMiddleware());
    }

    public function test_subject_offering_writes_are_gated_to_the_program_chair_role(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.subject-offerings.store');

        $this->assertNotNull($route);
        $this->assertContains('role:program_chair', $route->gatherMiddleware());

        $readRoute = Route::getRoutes()->getByName('api.v1.subject-offerings.index');
        $this->assertNotNull($readRoute);
        $this->assertNotContains('role:program_chair', $readRoute->gatherMiddleware());
    }

    public function test_academic_term_creation_is_gated_to_the_registrar_head_role(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.academic-terms.store');

        $this->assertNotNull($route);
        $this->assertContains('role:registrar_head', $route->gatherMiddleware());

        $archiveAndCreateNextRoute = Route::getRoutes()->getByName('api.v1.academic-terms.archive-and-create-next');
        $this->assertNotNull($archiveAndCreateNextRoute);
        $this->assertContains('role:registrar_head', $archiveAndCreateNextRoute->gatherMiddleware());

        $readRoute = Route::getRoutes()->getByName('api.v1.academic-terms.index');
        $this->assertNotNull($readRoute);
        $this->assertNotContains('role:registrar_head', $readRoute->gatherMiddleware());
    }

    public function test_ched_reports_are_gated_to_the_registrar_head_role(): void
    {
        foreach (['api.v1.ched-reports.show', 'api.v1.ched-reports.export'] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Missing route {$name}.");
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertContains('role:registrar_head', $route->gatherMiddleware());
        }
    }

    public function test_staff_invitations_are_gated_to_the_registrar_head_role(): void
    {
        $gated = [
            'api.v1.staff-invitations.index',
            'api.v1.staff-invitations.store',
            'api.v1.staff-invitations.resend',
        ];

        foreach ($gated as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Missing route {$name}.");
            $this->assertContains('role:registrar_head', $route->gatherMiddleware());
        }
    }

    public function test_faculty_input_writes_are_gated_to_the_faculty_role(): void
    {
        $gated = [
            'api.v1.faculty-availabilities.store',
            'api.v1.faculty-availabilities.update',
            'api.v1.faculty-availabilities.destroy',
            'api.v1.faculty-specializations.update-proficiency',
            'api.v1.faculty-specializations.destroy',
            'api.v1.faculty-subject-preferences.store',
            'api.v1.faculty-subject-preferences.update',
            'api.v1.faculty-subject-preferences.destroy',
        ];

        foreach ($gated as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Missing route {$name}.");
            $this->assertContains('role:faculty', $route->gatherMiddleware());
        }

        $readRoute = Route::getRoutes()->getByName('api.v1.faculty-availabilities.index');
        $this->assertNotNull($readRoute);
        $this->assertNotContains('role:faculty', $readRoute->gatherMiddleware());

        $specializationsReadRoute = Route::getRoutes()->getByName('api.v1.faculty-specializations.index');
        $this->assertNotNull($specializationsReadRoute);
        $this->assertNotContains('role:faculty', $specializationsReadRoute->gatherMiddleware());

        $specializationsStoreRoute = Route::getRoutes()->getByName('api.v1.faculty-specializations.store');
        $this->assertNotNull($specializationsStoreRoute);
        $this->assertContains('role:faculty,program_chair', $specializationsStoreRoute->gatherMiddleware());
    }

    public function test_section_writes_are_gated_to_the_program_chair_role(): void
    {
        $gated = ['api.v1.sections.store', 'api.v1.sections.update'];

        foreach ($gated as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Missing route {$name}.");
            $this->assertContains('role:program_chair', $route->gatherMiddleware());
        }

        $readRoute = Route::getRoutes()->getByName('api.v1.sections.index');
        $this->assertNotNull($readRoute);
        $this->assertNotContains('role:program_chair', $readRoute->gatherMiddleware());
    }

    public function test_schedule_proposal_submission_is_gated_to_the_program_chair_role(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.schedule-proposals.store');

        $this->assertNotNull($route);
        $this->assertContains('role:program_chair', $route->gatherMiddleware());
    }

    /**
     * Unlike every other write route, a single PATCH here serves six
     * different transitions needing six different roles — so it carries no
     * `role:` middleware at all. ScheduleProposalPolicy resolves the right
     * ability per request instead. See ADR 0011.
     */
    public function test_schedule_proposal_transitions_carry_no_role_middleware(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.schedule-proposals.update');

        $this->assertNotNull($route);

        $roleMiddleware = array_filter(
            $route->gatherMiddleware(),
            static fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'role:'),
        );

        $this->assertSame([], array_values($roleMiddleware));
    }

    public function test_student_profile_provisioning_is_gated_to_the_admission_staff_role(): void
    {
        foreach ([
            'api.v1.student-profiles.index',
            'api.v1.student-profiles.store',
            'api.v1.student-profiles.show',
            'api.v1.student-profiles.update',
            'api.v1.student-profiles.account-setup-invitations.store',
            'api.v1.student-profiles.queue-link-resends.store',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Missing route {$name}.");
            $this->assertContains('role:admission_staff', $route->gatherMiddleware());
        }
    }

    public function test_freshman_payment_routes_are_for_accounting_and_the_student_list_is_for_the_registrar(): void
    {
        foreach ([
            'api.v1.student-profiles.freshman-payment-preview.show',
            'api.v1.student-profiles.freshman-payment.store',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Missing route {$name}.");
            $this->assertContains('role:accounting_staff', $route->gatherMiddleware());
        }

        $list = Route::getRoutes()->getByName('api.v1.students.index');
        $this->assertNotNull($list);
        $this->assertContains('role:registrar_head,registrar_staff', $list->gatherMiddleware());
    }

    public function test_cross_cutting_read_routes_have_the_exact_role_boundaries(): void
    {
        $auditRoute = Route::getRoutes()->getByName('api.v1.audit-logs.index');

        $this->assertNotNull($auditRoute);
        $this->assertContains('role:registrar_head,super_admin', $auditRoute->gatherMiddleware());

        foreach ([
            'api.v1.it-control.accounts.archive',
            'api.v1.it-control.accounts.destroy',
            'api.v1.it-control.accounts.password',
            'api.v1.it-control.accounts.restore',
            'api.v1.it-control.accounts.update',
            'api.v1.it-control.students.index',
            'api.v1.it-control.faculty.index',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Missing route {$name}.");
            $this->assertContains('role:it_admin', $route->gatherMiddleware());
        }

        foreach ([
            'api.v1.queue-tickets.index',
            'api.v1.queue-tickets.update',
            'api.v1.queue-cycle.show',
            'api.v1.queue-cycle.cut-off',
            'api.v1.queue-cycle.resume',
            'api.v1.queue-kiosk-credential.show',
            'api.v1.queue-kiosk-credential.update',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Missing route {$name}.");
            $this->assertContains('role:accounting_staff', $route->gatherMiddleware());
        }

        foreach (['api.v1.notifications.index', 'api.v1.notifications.read'] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Missing route {$name}.");

            $roleMiddleware = array_filter(
                $route->gatherMiddleware(),
                static fn ($middleware): bool => is_string($middleware)
                    && str_starts_with($middleware, 'role:'),
            );

            $this->assertSame([], array_values($roleMiddleware));
        }
    }

    public function test_faculty_directory_is_gated_to_the_program_chair_role(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.faculty-members.index');

        $this->assertNotNull($route);
        $this->assertContains('role:program_chair,registrar_head,faculty', $route->gatherMiddleware());
    }

    /**
     * Own computed view, not a role-exclusive resource — no `role:`
     * middleware, matching `student-profile.show`. EligibleSubjectPolicy
     * resolves the student-only boundary instead.
     */
    public function test_eligible_subjects_carries_no_role_middleware(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.eligible-subjects.index');

        $this->assertNotNull($route);

        $roleMiddleware = array_filter(
            $route->gatherMiddleware(),
            static fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'role:'),
        );

        $this->assertSame([], array_values($roleMiddleware));
    }

    /**
     * Same shape as eligible-subjects: own computed view, student-only via
     * EligibleSubjectPolicy, not a `role:` middleware.
     */
    public function test_enrollment_blocks_carries_no_role_middleware(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.enrollment-blocks.index');

        $this->assertNotNull($route);

        $roleMiddleware = array_filter(
            $route->gatherMiddleware(),
            static fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'role:'),
        );

        $this->assertSame([], array_values($roleMiddleware));
    }

    /**
     * Own-record only, no role-exclusive resource — no `role:` middleware
     * on either route. EnrollmentPolicy resolves the student-only boundary.
     */
    public function test_enrollments_carry_no_role_middleware(): void
    {
        $names = [
            'api.v1.enrollments.index',
            'api.v1.enrollments.store',
            'api.v1.enrollments.update',
            'api.v1.enrollments.subjects.update',
            'api.v1.enrollments.payment',
            'api.v1.enrollments.cor-preview',
            'api.v1.enrollment-documents.index',
        ];

        foreach ($names as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Missing route {$name}.");

            $roleMiddleware = array_filter(
                $route->gatherMiddleware(),
                static fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'role:'),
            );

            $this->assertSame([], array_values($roleMiddleware));
        }
    }

    /**
     * Same shape as `test_enrollments_carry_no_role_middleware`: create is
     * Faculty-only writes and the Registrar checkpoint are resolved by the
     * relevant AcademicGradePolicy/SectionPolicy ability per request, never
     * by blanket `role:` middleware on the legacy or section-first routes.
     */
    public function test_academic_grades_carry_no_role_middleware(): void
    {
        foreach ([
            'api.v1.academic-grades.index',
            'api.v1.academic-grades.store',
            'api.v1.academic-grades.update',
            'api.v1.sections.grade-submission.index',
            'api.v1.sections.grades.show',
            'api.v1.sections.grades.store',
            'api.v1.sections.grades.submit',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Missing route {$name}.");

            $roleMiddleware = array_filter(
                $route->gatherMiddleware(),
                static fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'role:'),
            );

            $this->assertSame([], array_values($roleMiddleware));
        }
    }

    /**
     * Own-record only for the create side, role-resolved per-request for
     * the decide side — no `role:` middleware on any of the three routes.
     * EnrollmentPolicy::withdraw and WithdrawalRequestPolicy resolve the
     * boundaries instead.
     */
    public function test_withdrawal_routes_carry_no_role_middleware(): void
    {
        $names = [
            'api.v1.enrollments.withdraw',
            'api.v1.withdrawal-requests.index',
            'api.v1.withdrawal-requests.update',
        ];

        foreach ($names as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Missing route {$name}.");

            $roleMiddleware = array_filter(
                $route->gatherMiddleware(),
                static fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'role:'),
            );

            $this->assertSame([], array_values($roleMiddleware));
        }
    }

    /**
     * Two roles (Accounting Staff, Registrar Head), neither exclusive — no
     * `role:` middleware. `PaymentPolicy::viewAny` resolves the boundary.
     */
    public function test_payments_carries_no_role_middleware(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.payments.index');

        $this->assertNotNull($route);

        $roleMiddleware = array_filter(
            $route->gatherMiddleware(),
            static fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'role:'),
        );

        $this->assertSame([], array_values($roleMiddleware));
    }

    /**
     * Who may do what depends on the action, and on the credit's college for
     * a Program Chair (the Student saves and submits, the Program Chair maps
     * and endorses, the Registrar Head decides; ADR 0026, amended), so, like
     * the withdrawal routes, none of these carry `role:` middleware.
     * `TransfereeCreditPolicy` resolves each ability.
     */
    public function test_transferee_credit_routes_carry_no_role_middleware(): void
    {
        $names = [
            'api.v1.transferee-credits.index',
            'api.v1.transferee-credits.store',
            'api.v1.transferee-credits.update',
            'api.v1.transferee-credits.suggestions',
            'api.v1.transferee-credits.submit',
            'api.v1.transferee-credits.destroy',
            'api.v1.student-profiles.transferee-credits.suggestions',
            'api.v1.student-profiles.transferee-credits.submit-to-registrar-head',
            'api.v1.student-profiles.transferee-credits.approve-all',
        ];

        foreach ($names as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Missing route {$name}.");

            $roleMiddleware = array_filter(
                $route->gatherMiddleware(),
                static fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'role:'),
            );

            $this->assertSame([], array_values($roleMiddleware));
        }
    }

    /**
     * Faculty/Registrar Staff/Registrar Head, resolved by
     * `EnrollmentSubjectPolicy` — no `role:` middleware, the same shape as
     * the withdrawal and transferee-credit routes.
     */
    public function test_class_rosters_carry_no_role_middleware(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.class-rosters.index');

        $this->assertNotNull($route);

        $roleMiddleware = array_filter(
            $route->gatherMiddleware(),
            static fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'role:'),
        );

        $this->assertSame([], array_values($roleMiddleware));
    }

    /**
     * enrollment-summary is shared between two roles (`role:dean,
     * executive_director`); institution-summary, policy-settings, and
     * stuck-enrollments are each single-role. See DashboardPolicy/
     * StuckEnrollmentPolicy for the record-level re-check.
     */
    public function test_dashboard_routes_have_the_exact_role_boundaries(): void
    {
        $enrollmentSummary = Route::getRoutes()->getByName('api.v1.dashboards.enrollment-summary');
        $this->assertNotNull($enrollmentSummary);
        $this->assertContains('role:dean,executive_director,registrar_head,program_chair', $enrollmentSummary->gatherMiddleware());

        foreach ([
            'overview', 'sections', 'students', 'student',
        ] as $level) {
            $drillDown = Route::getRoutes()->getByName("api.v1.dashboards.enrollment-status.{$level}");
            $this->assertNotNull($drillDown, "Missing enrollment-status {$level} route.");
            // The status dashboard also serves Registrar, Accounting, and Admission
            // Staff, each limited to its own stage by EnrollmentStatusPopulation.
            $this->assertContains('role:dean,executive_director,registrar_head,program_chair,registrar_staff,accounting_staff,admission_staff', $drillDown->gatherMiddleware());
        }

        $institutionSummary = Route::getRoutes()->getByName('api.v1.dashboards.institution-summary');
        $this->assertNotNull($institutionSummary);
        $this->assertContains('role:executive_director', $institutionSummary->gatherMiddleware());

        $policySettings = Route::getRoutes()->getByName('api.v1.dashboards.policy-settings');
        $this->assertNotNull($policySettings);
        $this->assertContains('role:registrar_head', $policySettings->gatherMiddleware());

        $stuckEnrollments = Route::getRoutes()->getByName('api.v1.stuck-enrollments.index');
        $this->assertNotNull($stuckEnrollments);
        $this->assertContains('role:dean,registrar_head', $stuckEnrollments->gatherMiddleware());
    }

    public function test_the_public_queue_view_is_open_without_a_sign_in_but_throttled_per_link(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.public.queue-view.show');

        $this->assertNotNull($route);
        $middleware = $route->gatherMiddleware();
        $this->assertNotContains('auth:sanctum', $middleware);
        $this->assertContains('throttle:public-queue-view', $middleware);
        // Only a 48-character alphanumeric token can reach the controller at all.
        $this->assertSame('[A-Za-z0-9]{48}', $route->wheres['token']);
    }

    public function test_the_login_route_is_throttled(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.auth.login');

        $this->assertNotNull($route);
        $this->assertNotEmpty(
            array_filter(
                $route->gatherMiddleware(),
                static fn ($middleware): bool => is_string($middleware)
                    && str_contains($middleware, 'throttle'),
            ),
        );
    }
}
