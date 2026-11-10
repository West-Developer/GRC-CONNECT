<?php

use App\Http\Controllers\Api\V1\AcademicGradeController;
use App\Http\Controllers\Api\V1\AcademicRecordController;
use App\Http\Controllers\Api\V1\AcademicRecordStudentLookupController;
use App\Http\Controllers\Api\V1\AcademicTermController;
use App\Http\Controllers\Api\V1\AcademicTermSectionPlanController;
use App\Http\Controllers\Api\V1\AcademicTermWorkflowController;
use App\Http\Controllers\Api\V1\AdmissionRequirementController;
use App\Http\Controllers\Api\V1\AttritionReportController;
use App\Http\Controllers\Api\V1\AuditActorController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\Auth\AccountSetupController;
use App\Http\Controllers\Api\V1\Auth\FacultyAccountSetupController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\GoogleLoginController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\PrivacyNoticeAcceptanceController;
use App\Http\Controllers\Api\V1\Auth\ResendLoginOtpController;
use App\Http\Controllers\Api\V1\Auth\ResendStudentAccountSetupController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\Auth\StaffAccountSetupController;
use App\Http\Controllers\Api\V1\Auth\VerifyLoginOtpController;
use App\Http\Controllers\Api\V1\Billing\FeeScheduleController;
use App\Http\Controllers\Api\V1\Billing\PaymentDiscountSettingController;
use App\Http\Controllers\Api\V1\CashierPaymentCandidateController;
use App\Http\Controllers\Api\V1\CashierStudentLookupController;
use App\Http\Controllers\Api\V1\CashierTransactionController;
use App\Http\Controllers\Api\V1\ChedReportController;
use App\Http\Controllers\Api\V1\ClassRosterController;
use App\Http\Controllers\Api\V1\CurrentCurriculumSubjectController;
use App\Http\Controllers\Api\V1\CurriculumController;
use App\Http\Controllers\Api\V1\CurriculumMigrationController;
use App\Http\Controllers\Api\V1\CurriculumSubjectPlacementController;
use App\Http\Controllers\Api\V1\Dashboard\EnrollmentStatusController;
use App\Http\Controllers\Api\V1\Dashboard\EnrollmentSummaryController;
use App\Http\Controllers\Api\V1\Dashboard\InstitutionSummaryController;
use App\Http\Controllers\Api\V1\Dashboard\PolicySettingsController;
use App\Http\Controllers\Api\V1\Dashboard\ProgramChairAnalyticsSummaryController;
use App\Http\Controllers\Api\V1\Dashboard\StuckEnrollmentController;
use App\Http\Controllers\Api\V1\EligibleSubjectController;
use App\Http\Controllers\Api\V1\EnrollmentBlockController;
use App\Http\Controllers\Api\V1\EnrollmentChangeRequestController;
use App\Http\Controllers\Api\V1\EnrollmentController;
use App\Http\Controllers\Api\V1\EnrollmentDocumentController;
use App\Http\Controllers\Api\V1\EnrollmentMovementController;
use App\Http\Controllers\Api\V1\EnrollmentPaymentQuoteController;
use App\Http\Controllers\Api\V1\EnrollmentScholarshipDiscountController;
use App\Http\Controllers\Api\V1\EnrollmentWindowController;
use App\Http\Controllers\Api\V1\FacultyAvailabilityController;
use App\Http\Controllers\Api\V1\FacultyCurriculumSubjectPreferenceController;
use App\Http\Controllers\Api\V1\FacultyInvitationController;
use App\Http\Controllers\Api\V1\FacultyLoadLimitController;
use App\Http\Controllers\Api\V1\FacultyLoadReportController;
use App\Http\Controllers\Api\V1\FacultyMemberController;
use App\Http\Controllers\Api\V1\FacultyPreferenceCatalogController;
use App\Http\Controllers\Api\V1\FacultyProfileController;
use App\Http\Controllers\Api\V1\FacultySpecializationController;
use App\Http\Controllers\Api\V1\FacultySubjectPreferenceController;
use App\Http\Controllers\Api\V1\FacultyTeachingHistoryController;
use App\Http\Controllers\Api\V1\FreshmanPaymentController;
use App\Http\Controllers\Api\V1\GradeSlipController;
use App\Http\Controllers\Api\V1\GraduateController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\HonorsReportController;
use App\Http\Controllers\Api\V1\ItControl\AccountController as ItControlAccountController;
use App\Http\Controllers\Api\V1\ItControl\FacultyAccountController;
use App\Http\Controllers\Api\V1\ItControl\StudentAccountController as ItControlStudentAccountController;
use App\Http\Controllers\Api\V1\LectureLabAdjacencyController;
use App\Http\Controllers\Api\V1\NoCreditDeclarationController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PrivacyNoticeController;
use App\Http\Controllers\Api\V1\ProgramChairReviewCountsController;
use App\Http\Controllers\Api\V1\ProgramController;
use App\Http\Controllers\Api\V1\ProspectusController;
use App\Http\Controllers\Api\V1\PublicQueueViewController;
use App\Http\Controllers\Api\V1\QueueCycleController;
use App\Http\Controllers\Api\V1\QueueKioskCredentialController;
use App\Http\Controllers\Api\V1\QueueTicketController;
use App\Http\Controllers\Api\V1\RegistrarStudentController;
use App\Http\Controllers\Api\V1\RoomCatalogEntryController;
use App\Http\Controllers\Api\V1\RoomOccupancyController;
use App\Http\Controllers\Api\V1\RoomOccupancySummaryController;
use App\Http\Controllers\Api\V1\ScheduleGenerationRunController;
use App\Http\Controllers\Api\V1\ScheduleProposalController;
use App\Http\Controllers\Api\V1\SectionChangeRequestController;
use App\Http\Controllers\Api\V1\SectionController;
use App\Http\Controllers\Api\V1\SectionGradeController;
use App\Http\Controllers\Api\V1\SectionProfessorController;
use App\Http\Controllers\Api\V1\StaffInvitationController;
use App\Http\Controllers\Api\V1\StatementOfAccountController;
use App\Http\Controllers\Api\V1\StudentAccountController;
use App\Http\Controllers\Api\V1\StudentCreditMappingController;
use App\Http\Controllers\Api\V1\StudentProfileChangeRequestController;
use App\Http\Controllers\Api\V1\StudentProfileController;
use App\Http\Controllers\Api\V1\StudentQueueViewController;
use App\Http\Controllers\Api\V1\StudentSchedulePreferenceController;
use App\Http\Controllers\Api\V1\SubjectController;
use App\Http\Controllers\Api\V1\SubjectOfferingController;
use App\Http\Controllers\Api\V1\SubjectWaiverController;
use App\Http\Controllers\Api\V1\SubjectWithdrawalController;
use App\Http\Controllers\Api\V1\SuperAdmin\ActingContextController;
use App\Http\Controllers\Api\V1\SuperAdmin\UserAccountController;
use App\Http\Controllers\Api\V1\TorDocumentController;
use App\Http\Controllers\Api\V1\TransfereeCreditController;
use App\Http\Controllers\Api\V1\WithdrawalRequestController;
use App\Http\Middleware\ApplySuperAdminActingContext;
use App\Http\Middleware\EnsureQueueKioskUsesDeviceSurface;
use App\Http\Middleware\EnsureStudentQueueClaimUsesKiosk;
use App\Http\Middleware\EnsureSuperAdminIsNotActing;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/health', HealthController::class)
        ->middleware('throttle:60,1')
        ->name('health');

    // ADR 0044: a freshman's emailed "view my queue number" link. No sign-in — the
    // token in the URL is the credential (see the controller).
    Route::get('/public/queue-view/{token}', [PublicQueueViewController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{48}')
        ->middleware('throttle:public-queue-view')
        ->name('public.queue-view.show');

    // ADR 0055: the Data Privacy Notice's version and contact. Public — it is read before anyone signs in.
    Route::get('/privacy-notice', PrivacyNoticeController::class)
        ->middleware('throttle:60,1')
        ->name('privacy-notice');

    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::post('/account-setup', AccountSetupController::class)
            ->middleware('throttle:10,1')
            ->name('account-setup');

        Route::post('/resend-student-account-setup', ResendStudentAccountSetupController::class)
            ->middleware('throttle:5,1')
            ->name('resend-student-account-setup');

        Route::post('/faculty-account-setup', FacultyAccountSetupController::class)
            ->middleware('throttle:10,1')
            ->name('faculty-account-setup');

        Route::post('/staff-account-setup', StaffAccountSetupController::class)
            ->middleware('throttle:10,1')
            ->name('staff-account-setup');

        // Public. Per-account+IP throttling is applied inside the controller;
        // this coarse limiter is an additional flood guard.
        Route::post('/login', LoginController::class)
            ->middleware('throttle:30,1')
            ->name('login');

        // Public, always the identical generic response (no account
        // enumeration) — same risk profile as resend-student-account-setup.
        Route::post('/forgot-password', ForgotPasswordController::class)
            ->middleware('throttle:5,1')
            ->name('forgot-password');

        // Public, validates a guessable 6-digit code — same risk profile as
        // account-setup.
        Route::post('/reset-password', ResetPasswordController::class)
            ->middleware('throttle:10,1')
            ->name('reset-password');

        // Public — the challenge token is the only "identity" either of these
        // carries, and it is opaque and single-use. Same risk profile as
        // account-setup for verify; resend gets resend-student-account-setup's
        // tighter limit since it can be pressed repeatedly by a stalled user.
        Route::post('/login/verify-otp', VerifyLoginOtpController::class)
            ->middleware('throttle:10,1')
            ->name('login.verify-otp');

        Route::post('/login/resend-otp', ResendLoginOtpController::class)
            ->middleware('throttle:5,1')
            ->name('login.resend-otp');

        // Public — the credential is a Google-signed ID token, not a
        // brute-forceable secret, so no per-account RateLimiter is needed
        // here; this coarse limiter is only a flood guard.
        Route::post('/google', GoogleLoginController::class)
            ->middleware('throttle:20,1')
            ->name('google');

        Route::middleware(['auth:sanctum', EnsureUserIsActive::class, ApplySuperAdminActingContext::class, EnsureQueueKioskUsesDeviceSurface::class])->group(function (): void {
            Route::post('/logout', LogoutController::class)->name('logout');
            Route::get('/me', MeController::class)->name('me');
            // ADR 0055: the signed-in person agrees to the current Data Privacy Notice.
            Route::post('/privacy-notice/accept', [PrivacyNoticeAcceptanceController::class, 'store'])
                ->middleware('throttle:30,1')
                ->name('privacy-notice.accept');
        });
    });

    // Readable by every role; ProgramPolicy/AcademicTermPolicy plus each
    // model's visibleTo() scope decide which rows a given role receives.
    Route::middleware(['auth:sanctum', EnsureUserIsActive::class, ApplySuperAdminActingContext::class, EnsureQueueKioskUsesDeviceSurface::class, 'throttle:60,1'])->group(function (): void {
        Route::get('/programs', ProgramController::class)->name('programs');
        Route::get('/academic-terms', [AcademicTermController::class, 'index'])->name('academic-terms.index');
        Route::patch('/academic-terms/{academicTerm}', [AcademicTermController::class, 'update'])->name('academic-terms.update');
        Route::patch('/academic-terms/{academicTerm}/draft-identity', [AcademicTermController::class, 'updateDraftIdentity'])->name('academic-terms.draft-identity.update');

        // GET is readable by every role (AcademicTermPolicy::view gates a
        // learner-scoped role to learner-visible terms, same as the term
        // list above); PATCH is Registrar Head only via
        // AcademicTermPolicy::update — no role: middleware, same pattern as
        // the term-transition route above it.
        Route::get('/academic-terms/{academicTerm}/enrollment-windows', [EnrollmentWindowController::class, 'index'])->name('academic-terms.enrollment-windows.index');
        Route::patch('/academic-terms/{academicTerm}/enrollment-schedule', [EnrollmentWindowController::class, 'update'])->name('academic-terms.enrollment-schedule.update');
        Route::get('/academic-term-workflows', [AcademicTermWorkflowController::class, 'index'])->name('academic-term-workflows.index');
        Route::patch('/academic-term-workflows/{workflow}', [AcademicTermWorkflowController::class, 'update'])->name('academic-term-workflows.update');
        Route::get('/subjects', SubjectController::class)->name('subjects');
        Route::get('/curricula', [CurriculumController::class, 'index'])->name('curricula.index');

        // Every transition (submit, dean_approve, dean_return,
        // executive_approve, executive_return) needs a *different* role, so
        // a single blanket `role:` middleware doesn't fit this one route —
        // CurriculumPolicy resolves the right ability per request, same
        // shape as schedule-proposals.update. See ADR 0011.
        Route::patch('/curricula/{curriculum}/transition', [CurriculumController::class, 'transition'])->name('curricula.transition');
        Route::get('/subject-offerings', [SubjectOfferingController::class, 'index'])->name('subject-offerings.index');
        Route::get('/academic-term-section-plans', [AcademicTermSectionPlanController::class, 'index'])->name('academic-term-section-plans.index');
        Route::get('/faculty-availabilities', [FacultyAvailabilityController::class, 'index'])->name('faculty-availabilities.index');
        Route::get('/faculty-specializations', [FacultySpecializationController::class, 'index'])->name('faculty-specializations.index');
        Route::get('/faculty-subject-preferences', [FacultySubjectPreferenceController::class, 'index'])->name('faculty-subject-preferences.index');
        Route::get('/room-options', RoomCatalogEntryController::class)->name('room-options.index');
        Route::get('/room-occupancy', RoomOccupancyController::class)->name('room-occupancy.index');
        Route::get('/room-occupancy-summary', RoomOccupancySummaryController::class)->name('room-occupancy-summary.index');
        Route::get('/fee-schedules', [FeeScheduleController::class, 'index'])->name('fee-schedules.index');
        Route::put('/fee-schedules', [FeeScheduleController::class, 'update'])->name('fee-schedules.update');
        // ADR 0046: the full-payment discount's percentage and validity. The Registrar Head edits it
        // (PaymentDiscountSettingPolicy); Accounting Staff may read it. No `role:` middleware.
        Route::get('/payment-discount-settings', [PaymentDiscountSettingController::class, 'show'])->name('payment-discount-settings.show');
        Route::put('/payment-discount-settings', [PaymentDiscountSettingController::class, 'update'])->name('payment-discount-settings.update');
        Route::get('/sections', [SectionController::class, 'index'])->name('sections.index');
        Route::get('/sections/grade-submission', [SectionGradeController::class, 'index'])->name('sections.grade-submission.index');
        Route::get('/sections/{section}/grades', [SectionGradeController::class, 'show'])->name('sections.grades.show');
        Route::post('/sections/{section}/grades', [SectionGradeController::class, 'store'])->name('sections.grades.store');
        Route::post('/sections/{section}/grades/submit', [SectionGradeController::class, 'submit'])->name('sections.grades.submit');
        Route::get('/schedule-proposals', [ScheduleProposalController::class, 'index'])->name('schedule-proposals.index');
        Route::get('/schedule-proposals/{scheduleProposal}/sections', [ScheduleProposalController::class, 'sections'])->name('schedule-proposals.sections');
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

        // Every transition (dean_approve, dean_return, executive_approve,
        // executive_return, publish, close) needs a *different* role, so a
        // single blanket `role:` middleware doesn't fit this one route —
        // ScheduleProposalPolicy resolves the right ability per request. See
        // ADR 0011.
        Route::patch('/schedule-proposals/{scheduleProposal}', [ScheduleProposalController::class, 'update'])->name('schedule-proposals.update');

        // Own-record only — no role gate beyond authentication, since the
        // Policy resolves "whose profile is this" the same way auth/me does.
        Route::get('/student-profile', [StudentProfileController::class, 'show'])->name('student-profile.show');
        Route::get('/student-profile-change-requests', [StudentProfileChangeRequestController::class, 'index'])->name('student-profile-change-requests.index');
        Route::post('/student-profile-change-requests', [StudentProfileChangeRequestController::class, 'store'])->name('student-profile-change-requests.store');
        Route::patch('/student-profile-change-requests/{studentProfileChangeRequest}', [StudentProfileChangeRequestController::class, 'update'])->name('student-profile-change-requests.update');
        Route::delete('/student-profile-change-requests/{studentProfileChangeRequest}', [StudentProfileChangeRequestController::class, 'destroy'])->name('student-profile-change-requests.destroy');
        Route::patch('/student-profile-change-requests/{studentProfileChangeRequest}/decision', [StudentProfileChangeRequestController::class, 'decide'])->name('student-profile-change-requests.decision.update');

        // Student owns their account summary; Accounting Staff may look up
        // the served Student's account and record a balance-only receipt.
        // StudentProfilePolicy applies the narrower account-specific gate.
        Route::get('/student-account', [StudentAccountController::class, 'showOwn'])->name('student-account.show-own');
        // Statement of Account: the same `viewAccount` ability (ADR 0036).
        // A Student's own Admission requirements checklist (read-only; ADR 0037).
        Route::get('/me/admission-requirements', [AdmissionRequirementController::class, 'showOwn'])->middleware('role:student')->name('me.admission-requirements.show');
        Route::get('/me/statement-of-account', [StatementOfAccountController::class, 'showOwn'])->name('me.statement-of-account.show');
        Route::get('/me/statement-of-account/pdf', [StatementOfAccountController::class, 'pdfOwn'])->name('me.statement-of-account.pdf');
        Route::get('/students/{student}/statement-of-account', [StatementOfAccountController::class, 'show'])->whereNumber('student')->name('students.statement-of-account.show');
        Route::get('/students/{student}/statement-of-account/pdf', [StatementOfAccountController::class, 'pdf'])->whereNumber('student')->name('students.statement-of-account.pdf');
        Route::get('/students/{student}/account', [StudentAccountController::class, 'show'])->name('students.account.show');
        Route::post('/students/{student}/account-payments', [StudentAccountController::class, 'store'])->name('students.account-payments.store');

        // Own-record only, same shape as student-profile.show — no role
        // gate beyond authentication; StudentSchedulePreferencePolicy
        // resolves "student role only, and only their own row" for both
        // the GET and the upsert PUT.
        Route::get('/student-schedule-preferences', [StudentSchedulePreferenceController::class, 'show'])->name('student-schedule-preferences.show');
        Route::put('/student-schedule-preferences', [StudentSchedulePreferenceController::class, 'update'])->name('student-schedule-preferences.update');

        // Own computed view, not a stored resource — EligibleSubjectPolicy
        // resolves "student role only" the same way FacultyMemberPolicy
        // resolves "program chair only" for the faculty directory.
        Route::get('/eligible-subjects', EligibleSubjectController::class)->name('eligible-subjects.index');

        // Blocks a regular student may enrol into as a unit. Irregular
        // students use /eligible-subjects instead — this always returns []
        // for them, never an error.
        Route::get('/enrollment-blocks', EnrollmentBlockController::class)->name('enrollment-blocks.index');

        // Role-scoped read (Student own, Registrar Head all, Accounting
        // pending_payment only — Enrollment::scopeVisibleTo) and Student-only
        // submission.
        Route::get('/enrollments', [EnrollmentController::class, 'index'])->name('enrollments.index');
        Route::post('/enrollments', [EnrollmentController::class, 'store'])->name('enrollments.store');

        // Two Registrar Head checkpoints (registrar_approve/registrar_reject
        // at the approval queue, void at the payment-pending queue) share one
        // route the same way schedule-proposal transitions do — no single
        // `role:` middleware fits, so EnrollmentPolicy resolves the ability
        // per `action`. See ADR 0011.
        Route::patch('/enrollments/{enrollment}', [EnrollmentController::class, 'update'])->name('enrollments.update');

        // Program Head only, own college, and only while the enrollment sits
        // at their own review stage: add/remove whole subjects from a
        // student's proposed schedule before deciding on it.
        Route::patch('/enrollments/{enrollment}/subjects', [EnrollmentController::class, 'reviseSubjects'])->name('enrollments.subjects.update');

        // FR-FIN-007–009: Accounting-only, idempotent payment confirmation
        // + COR generation. No `role:` middleware — EnrollmentPolicy
        // resolves `confirmPayment` the same way it resolves the other two
        // Registrar Head checkpoints.
        Route::post('/enrollments/{enrollment}/payment', [EnrollmentController::class, 'confirmPayment'])->name('enrollments.payment');
        // ADR 0046: what the Cashier's modal quotes before confirming (amount due and what paying in full collects).
        Route::get('/enrollments/{enrollment}/payment-quote', EnrollmentPaymentQuoteController::class)->name('enrollments.payment-quote.show');
        Route::get('/enrollments/{enrollment}/cor-preview', [EnrollmentController::class, 'corPreview'])->name('enrollments.cor-preview');

        // Accounting may correct financial assessment lines while payment is
        // still pending. The action itself locks the record and rejects any
        // payment/COR-finalized assessment.
        Route::patch('/enrollments/{enrollment}/assessment', [EnrollmentController::class, 'adjustAssessment'])->name('enrollments.assessment.update');

        // ADR 0025: the Cashier's Payee/Scholar choice at payment time. PUT
        // assigns a 100/40/20% scholarship as a negative assessment line;
        // DELETE (Regular payee) removes it. Same authorization and the same
        // pending-payment/no-payment rule as the fee adjustment above.
        Route::put('/enrollments/{enrollment}/scholarship-discount', [EnrollmentScholarshipDiscountController::class, 'update'])->name('enrollments.scholarship-discount.update');
        Route::delete('/enrollments/{enrollment}/scholarship-discount', [EnrollmentScholarshipDiscountController::class, 'destroy'])->name('enrollments.scholarship-discount.destroy');

        // Student own; Accounting Staff and Registrar readers can view COR
        // history through EnrollmentDocument::scopeVisibleTo.
        Route::get('/enrollment-documents', [EnrollmentDocumentController::class, 'index'])->name('enrollment-documents.index');
        Route::get('/enrollment-documents/{enrollmentDocument}', [EnrollmentDocumentController::class, 'show'])->name('enrollment-documents.show');
        Route::get('/enrollment-documents/{enrollmentDocument}/pdf', [EnrollmentDocumentController::class, 'downloadPdf'])->name('enrollment-documents.pdf');

        // Accounting Staff's own payment history, plus Registrar Head
        // oversight — a narrower read than widening Enrollment::scopeVisibleTo,
        // since `payments` rows never disappear the way an enrollment does
        // once ConfirmPayment moves it out of pending_payment. No `role:`
        // middleware — PaymentPolicy::viewAny resolves both roles; no
        // per-row scoping distinguishes them further.
        Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');

        // A normalized, read-only Cashier history over enrollment-confirmation
        // and balance-payment receipts. `PaymentPolicy::viewAny` keeps this
        // to Accounting Staff and Registrar Head without changing /payments.
        Route::get('/cashier-transactions', [CashierTransactionController::class, 'index'])->name('cashier-transactions.index');

        // Exact, non-mutating candidate lookup for the Cashier workflow.
        // QueueTicketPolicy limits it to Accounting Staff; the UI separately
        // delegates any actual serving transition to the existing endpoint.
        Route::get('/cashier-payment-candidates', [CashierPaymentCandidateController::class, 'show'])->name('cashier-payment-candidates.show');

        // The Cashier's general student search for the Advance Payment page.
        // Unlike the candidate lookup above it depends on no enrollment or
        // queue state, so an already-enrolled student can still be found.
        Route::get('/cashier-student-lookup', [CashierStudentLookupController::class, 'index'])->name('cashier-student-lookup.index');

        // FR-FIN-004 / PRD §4.2 rule 7: Student-only, own `enrolled`
        // enrollment. No `role:` middleware — EnrollmentPolicy::withdraw
        // resolves the instance-level ownership check.
        Route::post('/enrollments/{enrollment}/withdraw', [EnrollmentController::class, 'withdraw'])->name('enrollments.withdraw');

        // Role-scoped read (Student own, Registrar Staff and Registrar Head
        // all — WithdrawalRequest::scopeVisibleTo); `decide` (approve/reject)
        // is Registrar Staff only per PRD §3.8's literal role assignment.
        // No `role:` middleware — WithdrawalRequestPolicy resolves both.
        Route::get('/withdrawal-requests', [WithdrawalRequestController::class, 'index'])->name('withdrawal-requests.index');
        Route::patch('/withdrawal-requests/{withdrawalRequest}', [WithdrawalRequestController::class, 'update'])->name('withdrawal-requests.update');

        // Phase 7: Student-only, own `enrolled` enrollment, only inside the
        // add/drop window (AddDropWindowResolver). No `role:` middleware —
        // EnrollmentPolicy::requestChange resolves the instance-level
        // ownership check, the same shape as `enrollments/{enrollment}/withdraw`.
        Route::post('/enrollments/{enrollment}/change-requests', [EnrollmentChangeRequestController::class, 'store'])->name('enrollments.change-requests.store');

        // FR-FIN-006 (queue kiosk claim): Student claims their own ticket,
        // or Accounting Staff issues one on a student's behalf at the
        // front desk. No `role:` middleware — EnrollmentPolicy::
        // claimQueueTicket resolves both cases; see ClaimQueueTicket. Sits
        // outside the accounting-only queue-tickets group below since a
        // Student must also reach it.
        Route::post('/queue-tickets', [QueueTicketController::class, 'store'])
            ->middleware(EnsureStudentQueueClaimUsesKiosk::class)
            ->name('queue-tickets.store');

        // PRD §5.3 FR-FIN-006: the student's own read-only queue status —
        // stage, own ticket + position, and the board (now serving, next
        // up). No per-record ownership dimension beyond "you are the
        // signed-in student", so the route-level role gate is enough.
        Route::middleware('role:student')->group(function (): void {
            Route::get('/queue-status', [StudentQueueViewController::class, 'show'])->name('queue-status.show');
        });

        // Role-scoped read (Student own, Registrar Head and Registrar Staff
        // all — EnrollmentChangeRequest::scopeVisibleTo); `decide`
        // (approve/reject) is Registrar Head only — the opposite role
        // assignment from withdrawal-requests, per explicit user direction.
        // No `role:` middleware — EnrollmentChangeRequestPolicy resolves both.
        Route::get('/enrollment-change-requests', [EnrollmentChangeRequestController::class, 'index'])->name('enrollment-change-requests.index');
        Route::patch('/enrollment-change-requests/{enrollmentChangeRequest}', [EnrollmentChangeRequestController::class, 'update'])->name('enrollment-change-requests.update');

        // FR-FIN-003 / PRD §3.8, §10.3, ADR 0026 (amended): a Student saves their
        // subjects as a private draft list and submits it, the Program Chair maps
        // and endorses, the Registrar Head decides (Registrar Staff read only).
        // No `role:` middleware — TransfereeCreditPolicy resolves every ability
        // per request, the same shape WithdrawalRequestController uses.
        Route::get('/transferee-credits', [TransfereeCreditController::class, 'index'])->name('transferee-credits.index');
        Route::post('/transferee-credits', [TransfereeCreditController::class, 'store'])->name('transferee-credits.store');
        Route::post('/transferee-credits/submit', [TransfereeCreditController::class, 'submit'])->name('transferee-credits.submit');
        Route::patch('/transferee-credits/{transfereeCredit}', [TransfereeCreditController::class, 'update'])->name('transferee-credits.update');
        Route::delete('/transferee-credits/{transfereeCredit}', [TransfereeCreditController::class, 'destroy'])->name('transferee-credits.destroy');
        Route::get('/transferee-credits/{transfereeCredit}/suggestions', [TransfereeCreditController::class, 'suggestions'])->name('transferee-credits.suggestions');

        // A Transferee or Returnee with nothing to credit says so, and the Program Chair confirms it so they can
        // enroll (ADR 0026 / 0050, amended). Policy-gated, no `role:`, like the credit routes above.
        Route::get('/no-credit-declaration', [NoCreditDeclarationController::class, 'show'])->name('no-credit-declaration.show');
        Route::post('/no-credit-declaration', [NoCreditDeclarationController::class, 'store'])->name('no-credit-declaration.store');
        Route::get('/no-credit-declarations', [NoCreditDeclarationController::class, 'index'])->name('no-credit-declarations.index');
        Route::post('/no-credit-declarations/{noCreditDeclaration}/confirm', [NoCreditDeclarationController::class, 'confirm'])
            ->whereNumber('noCreditDeclaration')
            ->name('no-credit-declarations.confirm');
        // The numbers on the Program Chair's Enrollment tabs: what is waiting for them.
        Route::get('/program-chair/review-counts', ProgramChairReviewCountsController::class)
            ->middleware('role:program_chair')
            ->name('program-chair.review-counts');

        // The student-level steps of the same flow (ADR 0026, amended): the
        // Program Chair's bulk suggestions and "send this student to the Registrar
        // Head", and the Registrar Head's "approve all". Policy-gated, no `role:`.
        Route::get('/student-profiles/{studentProfile}/transferee-credits/suggestions', [StudentCreditMappingController::class, 'suggestions'])->whereNumber('studentProfile')->name('student-profiles.transferee-credits.suggestions');
        Route::post('/student-profiles/{studentProfile}/transferee-credits/submit-to-registrar-head', [StudentCreditMappingController::class, 'submitToRegistrarHead'])->whereNumber('studentProfile')->name('student-profiles.transferee-credits.submit-to-registrar-head');
        Route::post('/student-profiles/{studentProfile}/transferee-credits/approve-all', [StudentCreditMappingController::class, 'approveAll'])->whereNumber('studentProfile')->name('student-profiles.transferee-credits.approve-all');

        // A transferee's / returnee's Transcript of Records, uploaded for credit
        // mapping (ADR 0026): the Student uploads, lists and removes their own;
        // the Program Chair (their college) and Registrar Staff / Head read.
        // No `role:` middleware — StudentTorDocumentPolicy resolves each ability.
        Route::get('/tor-documents', [TorDocumentController::class, 'index'])->name('tor-documents.index');
        Route::post('/tor-documents', [TorDocumentController::class, 'store'])->name('tor-documents.store');
        Route::get('/tor-documents/{torDocument}/file', [TorDocumentController::class, 'file'])->name('tor-documents.file');
        Route::delete('/tor-documents/{torDocument}', [TorDocumentController::class, 'destroy'])->name('tor-documents.destroy');

        // PRD §3.2 "View assigned teaching schedules and class rosters" —
        // Faculty own sections, Registrar Staff/Head all
        // (EnrollmentSubject::scopeVisibleTo). No `role:` middleware —
        // EnrollmentSubjectPolicy resolves the role-level boundary.
        Route::get('/class-rosters', [ClassRosterController::class, 'index'])->name('class-rosters.index');

        // Role-scoped read (Student own, Faculty own sections, Registrar
        // Head all — AcademicGrade::scopeVisibleTo). Writes carry no
        // `role:` middleware: create is Faculty-only (re-checked by
        // AcademicGradePolicy::create); the PATCH route serves a plain
        // content edit plus two further checkpoints (submit by Faculty,
        // lock by the Registrar Head), resolved the same way
        // EnrollmentController resolves registrar_approve/registrar_reject/
        // void. See ADR 0011.
        Route::get('/academic-grades', [AcademicGradeController::class, 'index'])->name('academic-grades.index');
        Route::get('/academic-grades/approval-professors', [AcademicGradeController::class, 'approvalProfessors'])->middleware('role:registrar_head')->name('academic-grades.approval-professors');
        Route::get('/academic-grades/approval-sections', [AcademicGradeController::class, 'approvalSections'])->middleware('role:registrar_head')->name('academic-grades.approval-sections');
        Route::post('/academic-grades', [AcademicGradeController::class, 'store'])->name('academic-grades.store');
        Route::post('/academic-grades/lock-all', [AcademicGradeController::class, 'lockAll'])->middleware('role:registrar_head')->name('academic-grades.lock-all');
        Route::patch('/academic-grades/{academicGrade}', [AcademicGradeController::class, 'update'])->name('academic-grades.update');

        // A student's full academic history, not a single grade record —
        // gated by AcademicRecordPolicy (view-academic-record), not by
        // role: middleware, matching /eligible-subjects' shape.
        Route::get('/prospectus', ProspectusController::class)->name('prospectus.show');
        Route::get('/grade-slip', GradeSlipController::class)->name('grade-slip.show');
        Route::get('/academic-record', AcademicRecordController::class)->name('academic-record.show');
        Route::get('/academic-record/students', AcademicRecordStudentLookupController::class)->name('academic-record.students');
        Route::get('/graduates', GraduateController::class)->name('graduates.index');

        // First production consumer of the `role` middleware (ADR 0008):
        // only the Program Chair authors curricula, matching the frontend's
        // existing "curriculum"/"subjects-prerequisites" module ownership.
        // CurriculumPolicy re-checks the role as defense in depth.
        Route::middleware('role:program_chair')->group(function (): void {
            Route::patch('/faculty-members/{facultyMember}/workforce-profile', [FacultyMemberController::class, 'updateWorkforceProfile'])->name('faculty-members.workforce-profile.update');
            Route::post('/curricula', [CurriculumController::class, 'store'])->name('curricula.store');
            Route::patch('/curricula/{curriculum}', [CurriculumController::class, 'update'])->name('curricula.update');
            Route::put('/curricula/{curriculum}/max-units', [CurriculumController::class, 'updateMaxUnits'])->name('curricula.max-units.update');
            Route::get('/programs/{program}/current-curriculum-subjects', CurrentCurriculumSubjectController::class)->name('programs.current-curriculum-subjects.index');
            Route::post('/curricula/{curriculum}/subject-placements', CurriculumSubjectPlacementController::class)->name('curricula.subject-placements.store');
            Route::get('/curricula/{curriculum}/migration-preview', [CurriculumMigrationController::class, 'preview'])->name('curricula.migrations.preview');
            Route::post('/curricula/{curriculum}/migrations', [CurriculumMigrationController::class, 'store'])->name('curricula.migrations.store');

            // Sections are the chair's schedule plan, same ownership as
            // curriculum authorship.
            Route::post('/sections', [SectionController::class, 'store'])->name('sections.store');
            Route::patch('/sections/{section}', [SectionController::class, 'update'])->name('sections.update');
            // A published section is final: the Program Head asks the Registrar Head
            // for a change instead (ADR 0032).
            Route::post('/sections/{section}/change-requests', [SectionChangeRequestController::class, 'store'])
                ->whereNumber('section')
                ->name('sections.change-requests.store');

            // Submitting (creating) a proposal is single-role, unlike its
            // transitions.
            Route::post('/schedule-proposals', [ScheduleProposalController::class, 'store'])->name('schedule-proposals.store');

            // Process 1.1: planning-only data, no learner-visibility concept,
            // same ownership as curriculum authorship.
            Route::post('/subject-offerings', [SubjectOfferingController::class, 'store'])->name('subject-offerings.store');
            Route::patch('/academic-terms/{academicTerm}/section-plan', [AcademicTermSectionPlanController::class, 'store'])->name('academic-term-section-plans.store');
            Route::post('/academic-terms/{academicTerm}/section-plan/release', [AcademicTermSectionPlanController::class, 'release'])->name('academic-term-section-plans.release');
            Route::post('/academic-terms/{academicTerm}/section-plan/auto-assign', [AcademicTermSectionPlanController::class, 'autoAssign'])->name('academic-term-section-plans.auto-assign');
            Route::post('/academic-terms/{academicTerm}/section-plan/submit', [AcademicTermSectionPlanController::class, 'submit'])->name('academic-term-section-plans.submit');
            Route::post('/academic-terms/{academicTerm}/schedule-generation-runs', [ScheduleGenerationRunController::class, 'store'])->name('schedule-generation-runs.store');
            Route::get('/academic-terms/{academicTerm}/schedule-generation-runs/latest', [ScheduleGenerationRunController::class, 'latest'])->name('schedule-generation-runs.latest');
            Route::get('/schedule-generation-runs/{scheduleGenerationRun}', [ScheduleGenerationRunController::class, 'show'])->name('schedule-generation-runs.show');
            Route::get('/academic-terms/{academicTerm}/lecture-lab-adjacency', [LectureLabAdjacencyController::class, 'index'])->name('lecture-lab-adjacency.index');
            Route::put('/academic-terms/{academicTerm}/faculty-load-threshold', [FacultyLoadReportController::class, 'updateThreshold'])->name('faculty-load-threshold.update');

            // A Chair invites professors into their own college only —
            // FacultyInvitationController scopes index/resend to the actor's
            // college, matching every other chair-owned resource here.
            Route::get('/faculty-invitations', [FacultyInvitationController::class, 'index'])->name('faculty-invitations.index');
            Route::post('/faculty-invitations', [FacultyInvitationController::class, 'store'])->name('faculty-invitations.store');
            Route::post('/faculty-invitations/{user}/resend', [FacultyInvitationController::class, 'resend'])->name('faculty-invitations.resend');

            Route::patch('/faculty-specializations/{facultySpecialization}', [FacultySpecializationController::class, 'update'])->name('faculty-specializations.update');
        });

        Route::get('/faculty-members', FacultyMemberController::class)
            ->middleware('role:program_chair,registrar_head,faculty')
            ->name('faculty-members.index');

        // Enrollment analytics are role-scoped by DashboardPolicy: Program
        // Chairs receive only their assigned college, while Registrar Head can
        // review all supported departments or a selected one. It must not live
        // in the Program Chair-only middleware group.
        Route::get('/dashboards/program-chair-analytics-summary', ProgramChairAnalyticsSummaryController::class)->name('dashboards.program-chair-analytics-summary');

        // A Faculty member writes only their own availability/preferences —
        // an own-record scope, not a role-exclusive resource like curricula.
        // The Policy re-checks professor_id === auth()->id() as defense in
        // depth (ADR 0008's pattern, applied to a new authorization shape).
        Route::middleware('role:faculty')->group(function (): void {
            Route::get('/faculty-preference-catalog', FacultyPreferenceCatalogController::class)->name('faculty-preference-catalog.index');
            Route::get('/faculty-curriculum-subject-preferences', [FacultyCurriculumSubjectPreferenceController::class, 'index'])->name('faculty-curriculum-subject-preferences.index');
            Route::post('/faculty-curriculum-subject-preferences', [FacultyCurriculumSubjectPreferenceController::class, 'store'])->name('faculty-curriculum-subject-preferences.store');
            Route::patch('/faculty-curriculum-subject-preferences/{facultyCurriculumPreference}', [FacultyCurriculumSubjectPreferenceController::class, 'update'])->name('faculty-curriculum-subject-preferences.update');
            Route::delete('/faculty-curriculum-subject-preferences/{facultyCurriculumPreference}', [FacultyCurriculumSubjectPreferenceController::class, 'destroy'])->name('faculty-curriculum-subject-preferences.destroy');
            Route::get('/faculty-teaching-history', [FacultyTeachingHistoryController::class, 'index'])->name('faculty-teaching-history.index');

            Route::post('/faculty-availabilities', [FacultyAvailabilityController::class, 'store'])->name('faculty-availabilities.store');
            Route::patch('/faculty-availabilities/{facultyAvailability}', [FacultyAvailabilityController::class, 'update'])->name('faculty-availabilities.update');
            Route::delete('/faculty-availabilities/{facultyAvailability}', [FacultyAvailabilityController::class, 'destroy'])->name('faculty-availabilities.destroy');

            Route::patch('/faculty-specializations/{facultySpecialization}/proficiency', [FacultySpecializationController::class, 'updateProficiency'])->name('faculty-specializations.update-proficiency');
            Route::delete('/faculty-specializations/{facultySpecialization}', [FacultySpecializationController::class, 'destroy'])->name('faculty-specializations.destroy');

            Route::post('/faculty-subject-preferences', [FacultySubjectPreferenceController::class, 'store'])->name('faculty-subject-preferences.store');
            Route::patch('/faculty-subject-preferences/{facultySubjectPreference}', [FacultySubjectPreferenceController::class, 'update'])->name('faculty-subject-preferences.update');
            Route::delete('/faculty-subject-preferences/{facultySubjectPreference}', [FacultySubjectPreferenceController::class, 'destroy'])->name('faculty-subject-preferences.destroy');
        });

        Route::post('/faculty-specializations', [FacultySpecializationController::class, 'store'])
            ->middleware('role:faculty,program_chair')
            ->name('faculty-specializations.store');

        // PRD §3.2: "Create new student accounts and initial profiles" —
        // first production consumer of the admission_staff role.
        Route::middleware('role:admission_staff')->group(function (): void {
            Route::get('/student-profiles', [StudentProfileController::class, 'index'])->name('student-profiles.index');
            // Before `{studentProfile}` so the literal path is never read as a profile id.
            Route::get('/student-profiles/next-student-number', [StudentProfileController::class, 'nextStudentNumber'])->name('student-profiles.next-student-number.show');
            Route::post('/student-profiles', [StudentProfileController::class, 'store'])->name('student-profiles.store');
            Route::get('/student-profiles/{studentProfile}', [StudentProfileController::class, 'showForAdmission'])->name('student-profiles.show');
            Route::patch('/student-profiles/{studentProfile}', [StudentProfileController::class, 'update'])->name('student-profiles.update');
            Route::post('/student-profiles/{studentProfile}/account-setup-invitations', [StudentProfileController::class, 'resendSetupInvitation'])->name('student-profiles.account-setup-invitations.store');
            Route::post('/student-profiles/{studentProfile}/queue-link-resends', [StudentProfileController::class, 'resendQueueLink'])->whereNumber('studentProfile')->name('student-profiles.queue-link-resends.store');
            // The Admission requirements checklist (ADR 0037).
            Route::get('/student-profiles/{studentProfile}/admission-requirements', [AdmissionRequirementController::class, 'show'])->whereNumber('studentProfile')->name('student-profiles.admission-requirements.show');
            Route::put('/student-profiles/{studentProfile}/admission-requirements/{requirementType}', [AdmissionRequirementController::class, 'update'])->whereNumber(['studentProfile', 'requirementType'])->name('student-profiles.admission-requirements.update');
            Route::get('/admission-requirement-types', [AdmissionRequirementController::class, 'indexTypes'])->name('admission-requirement-types.index');
            Route::post('/admission-requirement-types', [AdmissionRequirementController::class, 'storeType'])->name('admission-requirement-types.store');
        });

        // Program Head files and withdraws, Registrar Head decides; the policy
        // separates the two (ADR 0032).
        Route::middleware('role:program_chair,registrar_head')->group(function (): void {
            Route::get('/section-change-requests', [SectionChangeRequestController::class, 'index'])
                ->name('section-change-requests.index');
            Route::patch('/section-change-requests/{sectionChangeRequest}', [SectionChangeRequestController::class, 'update'])
                ->whereNumber('sectionChangeRequest')
                ->name('section-change-requests.update');
        });

        // Enrollment Analytics: drops, withdrawals, and course shifts (ADR 0034).
        Route::get('/analytics/enrollment-movements', [EnrollmentMovementController::class, 'index'])
            ->middleware('role:registrar_head,registrar_staff,program_chair')
            ->name('analytics.enrollment-movements');
        Route::post('/program-shifts', [EnrollmentMovementController::class, 'storeShift'])
            ->middleware('role:registrar_head,registrar_staff')
            ->name('program-shifts.store');

        // Teaching-load limits per employment type and per-professor overrides:
        // Program Head and Dean, own college only (ADR 0033).
        Route::middleware('role:program_chair,dean')->group(function (): void {
            // The report is the Dean's monitoring view too, scoped to their own college.
            Route::get('/academic-terms/{academicTerm}/faculty-load-report', [FacultyLoadReportController::class, 'show'])
                ->whereNumber('academicTerm')
                ->name('faculty-load-report.show');
            Route::put('/academic-terms/{academicTerm}/faculty-load-limits/{employmentType}', [FacultyLoadLimitController::class, 'updateLimit'])
                ->whereNumber('academicTerm')
                ->name('faculty-load-limits.update');
            Route::put('/academic-terms/{academicTerm}/faculty-load-overrides/{professor}', [FacultyLoadLimitController::class, 'updateOverride'])
                ->whereNumber(['academicTerm', 'professor'])
                ->name('faculty-load-overrides.update');
            Route::delete('/academic-terms/{academicTerm}/faculty-load-overrides/{professor}', [FacultyLoadLimitController::class, 'destroyOverride'])
                ->whereNumber(['academicTerm', 'professor'])
                ->name('faculty-load-overrides.destroy');
        });

        // The Dean assigns or unassigns the professor of a section in their own
        // college; nothing else about the section (ADR 0033).
        Route::put('/sections/{section}/professor', [SectionProfessorController::class, 'update'])
            ->middleware('role:dean')
            ->whereNumber('section')
            ->name('sections.professor.update');

        Route::middleware('role:registrar_head')->group(function (): void {
            // ADR 0049: the CHED Enrollment List and the NSTP forms 2-B / 2-A, previewed and downloaded
            // as CSV or as CHED's own template filled in. A named student list: Registrar Head only.
            Route::get('/ched-reports/{report}', [ChedReportController::class, 'show'])
                ->whereIn('report', ['enrollment-list', 'nstp-enrollment', 'nstp-graduates', 'nstp-summary'])
                ->name('ched-reports.show');
            Route::get('/ched-reports/{report}/export', [ChedReportController::class, 'export'])
                ->whereIn('report', ['enrollment-list', 'nstp-enrollment', 'nstp-graduates', 'nstp-summary'])
                ->name('ched-reports.export');
            // A professor's teaching profile: terms taught, sections, timetable data, and
            // grade-submission counts. Counts only, no student is named.
            Route::get('/faculty-members/{professor}/profile', FacultyProfileController::class)
                ->whereNumber('professor')
                ->name('faculty-members.profile.show');
            Route::get('/students/{studentProfile}/registrar-profile', [StudentProfileController::class, 'showForRegistrar'])
                ->whereNumber('studentProfile')
                ->name('students.registrar-profile.show');
            // Prerequisite waivers (ADR 0031): Registrar Head only.
            Route::get('/students/{studentProfile}/subject-waivers', [SubjectWaiverController::class, 'index'])
                ->whereNumber('studentProfile')
                ->name('students.subject-waivers.index');
            Route::post('/students/{studentProfile}/subject-waivers', [SubjectWaiverController::class, 'store'])
                ->whereNumber('studentProfile')
                ->name('students.subject-waivers.store');
            // The Registrar Head withdraws a student's enrolled subjects; each shows as W on the Prospectus.
            Route::get('/students/{studentProfile}/subject-withdrawals', [SubjectWithdrawalController::class, 'index'])
                ->whereNumber('studentProfile')
                ->name('students.subject-withdrawals.index');
            Route::post('/students/{studentProfile}/subject-withdrawals', [SubjectWithdrawalController::class, 'store'])
                ->whereNumber('studentProfile')
                ->name('students.subject-withdrawals.store');
            Route::delete('/subject-waivers/{waiver}', [SubjectWaiverController::class, 'destroy'])
                ->whereNumber('waiver')
                ->name('subject-waivers.destroy');
            Route::get('/analytics/attrition', AttritionReportController::class)
                ->name('analytics.attrition');
            Route::get('/dashboards/policy-settings', PolicySettingsController::class)
                ->name('dashboards.policy-settings');

            // PRD Process 1: only the Registrar Head creates academic terms.
            // AcademicTermPolicy re-checks the role as defense in depth.
            Route::post('/academic-terms', [AcademicTermController::class, 'store'])
                ->name('academic-terms.store');

            // Closing one cycle opens the next in the same transaction, so
            // the system never sits with no current term.
            Route::post('/academic-terms/{academicTerm}/archive-and-create-next', [AcademicTermController::class, 'archiveAndCreateNext'])
                ->name('academic-terms.archive-and-create-next');

            // Registrar Head invites an account for any staff/leadership
            // role except Student (Admission's own flow) and AdmissionStaff
            // — StaffInvitationController scopes to
            // UserRole::registrarInvitableCases(), not to one college.
            Route::get('/staff-invitations', [StaffInvitationController::class, 'index'])->name('staff-invitations.index');
            Route::post('/staff-invitations', [StaffInvitationController::class, 'store'])->name('staff-invitations.store');
            Route::post('/staff-invitations/{user}/resend', [StaffInvitationController::class, 'resend'])->name('staff-invitations.resend');
        });

        Route::middleware('role:registrar_head,super_admin')->group(function (): void {
            Route::get('/audit-logs', AuditLogController::class)->name('audit-logs.index');
            // The audit screen's per-user first level (stakeholder Doc 14).
            Route::get('/audit-logs/actors', AuditActorController::class)->name('audit-logs.actors');
        });

        Route::prefix('it-control')->name('it-control.')->middleware('role:it_admin')->group(function (): void {
            Route::get('/students', ItControlStudentAccountController::class)->name('students.index');
            Route::get('/faculty', FacultyAccountController::class)->name('faculty.index');
            // ADR 0054: edit, archive, restore and delete a Student or Faculty account (the gate refuses any other role's).
            Route::patch('/accounts/{user}', [ItControlAccountController::class, 'update'])->whereNumber('user')->name('accounts.update');
            Route::post('/accounts/{user}/password', [ItControlAccountController::class, 'changePassword'])->whereNumber('user')->middleware('throttle:10,1')->name('accounts.password');
            Route::post('/accounts/{user}/archive', [ItControlAccountController::class, 'archive'])->whereNumber('user')->name('accounts.archive');
            Route::post('/accounts/{user}/restore', [ItControlAccountController::class, 'restore'])->whereNumber('user')->name('accounts.restore');
            Route::delete('/accounts/{user}', [ItControlAccountController::class, 'destroy'])->whereNumber('user')->name('accounts.destroy');
        });

        Route::prefix('super-admin')->name('super-admin.')->middleware('super_admin')->group(function (): void {
            Route::put('/acting-context', [ActingContextController::class, 'update'])->name('acting-context.update');
            Route::delete('/acting-context', [ActingContextController::class, 'destroy'])->name('acting-context.destroy');

            Route::middleware(EnsureSuperAdminIsNotActing::class)->group(function (): void {
                Route::get('/users', [UserAccountController::class, 'index'])->name('users.index');
                Route::post('/users/invite', [UserAccountController::class, 'invite'])->name('users.invite');
                Route::patch('/users/{user}/role', [UserAccountController::class, 'changeRole'])->name('users.change-role');
                Route::patch('/users/{user}/status', [UserAccountController::class, 'updateStatus'])->name('users.update-status');
                Route::post('/users/{user}/setup-invitation', [UserAccountController::class, 'resendSetupInvitation'])->name('users.resend-setup-invitation');
                Route::post('/users/{user}/password-reset', [UserAccountController::class, 'sendPasswordReset'])->name('users.send-password-reset');
                Route::delete('/users/{user}/sessions', [UserAccountController::class, 'revokeSessions'])->name('users.revoke-sessions');
                Route::delete('/users/{user}', [UserAccountController::class, 'destroy'])->name('users.destroy');
            });
        });

        // Phase 7c: aggregate-only counts, never row-level enrollment data,
        // for the two roles Enrollment::scopeVisibleTo()/EnrollmentPolicy
        // otherwise exclude entirely (see ADR 0017). stuck-enrollments is the
        // one PRD-authorized exception (§3.5's "stuck-student reports"),
        // scoped to Dean only and returning student_number, never a full
        // Enrollment or student record.
        //
        // ADR 0024 adds a second, narrower exception: the Enrollment Dashboard
        // drill-down. `enrollment-status` and `.../sections` stay aggregate-only;
        // `.../students` and `.../students/{id}` return a fixed identity field
        // set, are limited to the actor's own college for Dean/Program Chair,
        // and write an audit entry (see EnrollmentStatusPopulation).
        Route::middleware('role:dean,executive_director,registrar_head,program_chair')->group(function (): void {
            Route::get('/dashboards/enrollment-summary', EnrollmentSummaryController::class)
                ->name('dashboards.enrollment-summary');
        });
        // Registrar Staff, Accounting Staff, and Admission Staff join the roles above
        // on the status dashboard only, each limited to the students waiting on them
        // (`EnrollmentStatusPopulation::query` applies the stage at every level).
        Route::middleware('role:dean,executive_director,registrar_head,program_chair,registrar_staff,accounting_staff,admission_staff')->group(function (): void {
            Route::get('/dashboards/enrollment-status', [EnrollmentStatusController::class, 'overview'])
                ->name('dashboards.enrollment-status.overview');
            Route::get('/dashboards/enrollment-status/sections', [EnrollmentStatusController::class, 'sections'])
                ->name('dashboards.enrollment-status.sections');
            Route::get('/dashboards/enrollment-status/students', [EnrollmentStatusController::class, 'students'])
                ->name('dashboards.enrollment-status.students');
            Route::get('/dashboards/enrollment-status/students/{studentProfile}', [EnrollmentStatusController::class, 'student'])
                ->whereNumber('studentProfile')
                ->name('dashboards.enrollment-status.student');
        });
        Route::middleware('role:dean')->group(function (): void {
            Route::get('/reports/honors', HonorsReportController::class)
                ->name('reports.honors');
        });
        Route::middleware('role:executive_director')->group(function (): void {
            Route::get('/dashboards/institution-summary', InstitutionSummaryController::class)
                ->name('dashboards.institution-summary');
        });
        Route::middleware('role:dean,registrar_head')->group(function (): void {
            Route::get('/stuck-enrollments', StuckEnrollmentController::class)
                ->name('stuck-enrollments.index');
        });

        // FR-FIN-006: both transitions are Accounting-only with no
        // per-ticket ownership dimension (unlike enrollments/academic
        // grades), so the coarse `role:` middleware fits here — re-checked
        // by QueueTicketPolicy as defense in depth.
        Route::middleware('role:accounting_staff')->group(function (): void {
            Route::get('/queue-kiosk-credential', [QueueKioskCredentialController::class, 'show'])->name('queue-kiosk-credential.show');
            Route::put('/queue-kiosk-credential', [QueueKioskCredentialController::class, 'update'])->name('queue-kiosk-credential.update');
            Route::get('/queue-tickets', [QueueTicketController::class, 'index'])->name('queue-tickets.index');
            Route::patch('/queue-tickets/{queueTicket}', [QueueTicketController::class, 'update'])->name('queue-tickets.update');
            Route::get('/queue-cycle', [QueueCycleController::class, 'show'])->name('queue-cycle.show');
            Route::post('/queue-cycle/cut-off', [QueueCycleController::class, 'cutOff'])->name('queue-cycle.cut-off');
            Route::post('/queue-cycle/resume', [QueueCycleController::class, 'resume'])->name('queue-cycle.resume');
            // ADR 0043: a freshman's first payment at the Cashier. The preview reads; the POST is idempotent.
            Route::get('/student-profiles/{studentProfile}/freshman-payment-preview', [FreshmanPaymentController::class, 'preview'])->whereNumber('studentProfile')->name('student-profiles.freshman-payment-preview.show');
            Route::post('/student-profiles/{studentProfile}/freshman-payment', [FreshmanPaymentController::class, 'store'])->whereNumber('studentProfile')->name('student-profiles.freshman-payment.store');
        });

        // The Registrar's read-only list of every student (it replaced the freshmen-only list of ADR 0043).
        Route::get('/students', [RegistrarStudentController::class, 'index'])
            ->middleware('role:registrar_head,registrar_staff')
            ->name('students.index');
    });
});
