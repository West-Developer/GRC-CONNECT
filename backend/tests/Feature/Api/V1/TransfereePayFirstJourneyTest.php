<?php

namespace Tests\Feature\Api\V1;

use App\Actions\Academic\IssueTransfereeQueueTicket;
use App\Actions\Enrollment\SubmitEnrollment;
use App\Domain\Academic\TransfereeCreditStatus;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\QueueTicketStatus;
use App\Domain\Identity\StudentType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Notifications\NotificationType;
use App\Domain\Organization\CollegeCode;
use App\Mail\EnrollmentConfirmedMail;
use App\Mail\StudentAccountSetupMail;
use App\Mail\TransfereeQueueTicketMail;
use App\Models\AcademicTerm;
use App\Models\AccountPayment;
use App\Models\Enrollment;
use App\Models\EnrollmentDocument;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\QueueTicket;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\TransfereeCredit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsBlockSections;
use Tests\Support\BuildsFreshmen;
use Tests\TestCase;

/**
 * A transferee whose credit mapping has closed picks subjects, which wait at the
 * payment hold (`awaiting_first_payment`) until the Cashier takes the first
 * payment, and only then reach the Registrar (ADR 0043, amended 2026-10-05).
 * Everything goes through the real endpoints; each request authenticates as
 * exactly one actor (the documented Sanctum gotcha).
 */
final class TransfereePayFirstJourneyTest extends TestCase
{
    use BuildsBlockSections;
    use BuildsFreshmen;
    use RefreshDatabase;

    /**
     * A transferee who can sign in and whose credit mapping is closed: one credit, rejected,
     * is enough for `EvaluateCreditMappingStatus` to call it complete.
     */
    private function transferee(string $category = 'regular', bool $paid = false, bool $withClosedCredit = true): StudentProfile
    {
        $profile = $this->freshmanProfile('2026-0900', StudentType::Transferee, $category);
        $profile->user->forceFill([
            'status' => UserStatus::Active, 'account_setup_completed_at' => now(), 'last_otp_verified_at' => now(),
        ])->save();

        if ($withClosedCredit) {
            $this->credit($profile, TransfereeCreditStatus::Rejected);
        }

        if ($paid) {
            $profile->forceFill(['freshman_payment_confirmed_at' => now()])->save();
        }

        return $profile->refresh();
    }

    private function credit(StudentProfile $profile, TransfereeCreditStatus $status): TransfereeCredit
    {
        static $sequence = 0;
        $sequence++;

        return TransfereeCredit::create([
            'student_id' => $profile->id, 'source_institution' => 'Other University',
            'source_subject_code' => 'X'.$sequence, 'source_subject_title' => 'Subject '.$sequence, 'source_grade' => '2.00',
            'credited_units' => 3, 'status' => $status,
        ]);
    }

    private function asUser(string $email): static
    {
        $token = (string) $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => self::FRESHMAN_TEST_PASSWORD])->json('data.token');
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function asStaff(UserRole $role, string $email): static
    {
        $this->staffUser($role, $email);

        return $this->asUser($email);
    }

    /**
     * @return array{profile: StudentProfile, term: AcademicTerm, section: Section, enrollmentId: int}
     */
    private function submittedBlock(bool $paid = false): array
    {
        $profile = $this->transferee('regular', $paid);
        $term = $this->ongoingTerm();
        $plan = $this->yearOnePlan($term, $profile->curriculum);
        $section = $this->blockSection($term, $plan, 'IT101', 'ITP1');

        $response = $this->asUser($profile->user->email)
            ->postJson('/api/v1/enrollments', ['academic_term_id' => $term->id, 'block_code' => 'IT101'])
            ->assertCreated();

        return ['profile' => $profile, 'term' => $term, 'section' => $section, 'enrollmentId' => (int) $response->json('data.id')];
    }

    public function test_a_regular_transferee_who_submits_a_block_waits_at_the_payment_hold_and_the_registrar_is_not_told(): void
    {
        $registrar = $this->staffUser(UserRole::RegistrarStaff, 'registrar@grc.test');

        ['profile' => $profile, 'section' => $section, 'enrollmentId' => $enrollmentId] = $this->submittedBlock();

        $enrollment = Enrollment::query()->findOrFail($enrollmentId);
        self::assertSame(EnrollmentStatus::AwaitingFirstPayment, $enrollment->status);
        self::assertSame(1, $section->fresh()->enrolled_count, 'the hold reserves the seat');
        self::assertSame(0, Notification::query()->where('user_id', $registrar->id)->where('type', NotificationType::EnrollmentSubmitted)->count());
        $told = Notification::query()->where('user_id', $profile->user_id)->where('type', NotificationType::EnrollmentSubmitted)->sole();
        self::assertStringContainsString('Cashier', $told->message);
        self::assertStringNotContainsString('pending Registrar approval', $told->message);
    }

    public function test_a_transferee_who_already_paid_goes_straight_to_the_registrar(): void
    {
        $registrar = $this->staffUser(UserRole::RegistrarStaff, 'registrar@grc.test');

        ['enrollmentId' => $enrollmentId] = $this->submittedBlock(paid: true);

        self::assertSame(EnrollmentStatus::PendingRegistrarApproval, Enrollment::query()->findOrFail($enrollmentId)->status);
        self::assertSame(1, Notification::query()->where('user_id', $registrar->id)->where('type', NotificationType::EnrollmentSubmitted)->count());
    }

    public function test_an_irregular_transferee_goes_to_the_program_chair_first_then_to_the_payment_hold(): void
    {
        $profile = $this->transferee('irregular');
        $term = $this->ongoingTerm();
        $plan = $this->yearOnePlan($term, $profile->curriculum);
        $section = $this->blockSection($term, $plan, 'IT101', 'ITP1', ['is_block_exclusive' => false]);
        $registrar = $this->staffUser(UserRole::RegistrarStaff, 'registrar@grc.test');
        // The Program Chair decides for students of their own college.
        $profile->program->update(['college' => CollegeCode::Ccs]);
        User::create([
            'name' => 'Test chair', 'email' => 'chair@grc.test', 'password' => self::FRESHMAN_TEST_PASSWORD,
            'role' => UserRole::ProgramChair, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
            'college' => CollegeCode::Ccs,
        ]);

        $enrollmentId = (int) $this->asUser($profile->user->email)
            ->postJson('/api/v1/enrollments', ['academic_term_id' => $term->id, 'sections' => [['section_id' => $section->id]]])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_program_head_approval')
            ->json('data.id');

        $this->asUser('chair@grc.test')
            ->patchJson("/api/v1/enrollments/{$enrollmentId}", ['action' => 'program_head_approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_first_payment');

        self::assertSame(EnrollmentStatus::AwaitingFirstPayment, Enrollment::query()->findOrFail($enrollmentId)->status);
        self::assertSame(0, Notification::query()->where('user_id', $registrar->id)->where('type', NotificationType::EnrollmentProgramHeadApproved)->count());
        $told = Notification::query()->where('user_id', $profile->user_id)->where('type', NotificationType::EnrollmentProgramHeadApproved)->sole();
        self::assertStringContainsString('Cashier', $told->message);
    }

    public function test_the_registrar_cannot_approve_an_enrollment_that_has_not_been_paid(): void
    {
        ['enrollmentId' => $enrollmentId] = $this->submittedBlock();

        $this->asStaff(UserRole::RegistrarStaff, 'registrar@grc.test')
            ->patchJson("/api/v1/enrollments/{$enrollmentId}", ['action' => 'registrar_approve'])
            ->assertUnprocessable();

        self::assertSame(EnrollmentStatus::AwaitingFirstPayment, Enrollment::query()->findOrFail($enrollmentId)->status);
    }

    public function test_the_student_can_cancel_while_waiting_at_the_hold_and_the_seat_is_given_back_once(): void
    {
        ['profile' => $profile, 'section' => $section, 'enrollmentId' => $enrollmentId] = $this->submittedBlock();

        $this->asUser($profile->user->email)
            ->patchJson("/api/v1/enrollments/{$enrollmentId}", ['action' => 'student_cancel', 'reason' => 'I will enroll next term.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        self::assertSame(0, $section->fresh()->enrolled_count);

        $this->asUser($profile->user->email)
            ->patchJson("/api/v1/enrollments/{$enrollmentId}", ['action' => 'student_cancel', 'reason' => 'Again.'])
            ->assertUnprocessable();
        self::assertSame(0, $section->fresh()->enrolled_count, 'a repeat releases nothing twice');
    }

    public function test_the_registrar_can_void_an_enrollment_at_the_hold_and_the_seat_is_given_back(): void
    {
        ['section' => $section, 'enrollmentId' => $enrollmentId] = $this->submittedBlock();

        $this->asStaff(UserRole::RegistrarStaff, 'registrar@grc.test')
            ->patchJson("/api/v1/enrollments/{$enrollmentId}", ['action' => 'void', 'reason' => 'Student asked to withdraw the request.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        self::assertSame(0, $section->fresh()->enrolled_count);
    }

    public function test_the_heads_approval_of_the_last_open_credit_issues_the_number_and_emails_the_live_link_once(): void
    {
        Mail::fake();
        $profile = $this->transferee(withClosedCredit: false);
        $this->credit($profile, TransfereeCreditStatus::Endorsed);
        $this->credit($profile, TransfereeCreditStatus::Endorsed);
        $url = "/api/v1/student-profiles/{$profile->id}/transferee-credits/approve-all";

        $this->asStaff(UserRole::RegistrarHead, 'head@grc.test')->postJson($url)->assertOk();

        $ticket = QueueTicket::query()->where('student_profile_id', $profile->id)->sole();
        Mail::assertSent(TransfereeQueueTicketMail::class, 1);
        Mail::assertSent(TransfereeQueueTicketMail::class, fn (TransfereeQueueTicketMail $mail): bool => $mail->hasTo($profile->user->email)
            && $mail->ticketNumber === $ticket->ticket_number
            && str_contains($mail->liveUrl, '/queue/live?t='));

        // A repeat approves nothing, issues nothing, emails nobody.
        $this->asUser('head@grc.test')->postJson($url)->assertOk();
        Mail::assertSent(TransfereeQueueTicketMail::class, 1);
        self::assertSame(1, QueueTicket::query()->count());
    }

    /**
     * Owner, 2026-10-15: the number comes when the Program Chair has passed the LAST pending credit
     * on to the Registrar Head, not when the Head approves.
     */
    public function test_the_chair_endorsing_credits_one_by_one_issues_the_number_only_with_the_last_pending_one(): void
    {
        Mail::fake();
        $profile = $this->transferee(withClosedCredit: false);
        $profile->program->update(['college' => CollegeCode::Ccs]);
        User::create([
            'name' => 'Test chair', 'email' => 'chair@grc.test', 'password' => self::FRESHMAN_TEST_PASSWORD,
            'role' => UserRole::ProgramChair, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
            'college' => CollegeCode::Ccs,
        ]);
        $subject = Subject::create(['code' => 'CS101', 'title' => 'Intro', 'units' => 3, 'status' => SubjectStatus::Active]);
        $first = $this->credit($profile, TransfereeCreditStatus::Pending);
        $second = $this->credit($profile, TransfereeCreditStatus::Pending);
        $first->forceFill(['subject_id' => $subject->id])->save();
        $second->forceFill(['subject_id' => $subject->id])->save();

        $this->asUser('chair@grc.test')
            ->patchJson("/api/v1/transferee-credits/{$first->id}", ['action' => 'endorse'])->assertOk();

        self::assertSame(0, QueueTicket::query()->count(), 'one credit is still with the Program Chair');
        Mail::assertNothingSent();

        $this->asUser('chair@grc.test')
            ->patchJson("/api/v1/transferee-credits/{$second->id}", ['action' => 'endorse'])->assertOk();

        self::assertSame(1, QueueTicket::query()->where('student_profile_id', $profile->id)->count());
        Mail::assertSent(TransfereeQueueTicketMail::class, 1);
    }

    public function test_the_registrar_heads_approval_adds_no_second_number_once_the_chair_has_passed_the_credits_on(): void
    {
        Mail::fake();
        $profile = $this->transferee(withClosedCredit: false);
        $first = $this->credit($profile, TransfereeCreditStatus::Endorsed);
        $second = $this->credit($profile, TransfereeCreditStatus::Endorsed);
        $this->app->make(IssueTransfereeQueueTicket::class)->whenMappingCloses(
            $profile,
            $this->staffUser(UserRole::RegistrarHead, 'head@grc.test'),
            new AuditRequestContext('test-close', null),
        );
        self::assertSame(1, QueueTicket::query()->where('student_profile_id', $profile->id)->count());

        $this->asUser('head@grc.test')
            ->patchJson("/api/v1/transferee-credits/{$first->id}", ['action' => 'approve'])->assertOk();
        $this->asUser('head@grc.test')
            ->patchJson("/api/v1/transferee-credits/{$second->id}", ['action' => 'reject', 'reason' => 'Not in the transcript.'])->assertOk();

        self::assertSame(1, QueueTicket::query()->where('student_profile_id', $profile->id)->count());
    }

    public function test_a_transferee_whose_credits_the_chair_passed_on_can_already_submit_a_block(): void
    {
        $profile = $this->transferee(withClosedCredit: false);
        $this->credit($profile, TransfereeCreditStatus::Endorsed);
        $term = $this->ongoingTerm();
        $plan = $this->yearOnePlan($term, $profile->curriculum);
        $this->blockSection($term, $plan, 'IT101', 'ITP1');

        $this->asUser($profile->user->email)
            ->postJson('/api/v1/enrollments', ['academic_term_id' => $term->id, 'block_code' => 'IT101'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_first_payment');
    }

    public function test_a_transferee_with_a_credit_still_with_the_program_chair_cannot_submit_a_block_yet(): void
    {
        $profile = $this->transferee(withClosedCredit: false);
        $this->credit($profile, TransfereeCreditStatus::Pending);
        $term = $this->ongoingTerm();
        $plan = $this->yearOnePlan($term, $profile->curriculum);
        $this->blockSection($term, $plan, 'IT101', 'ITP1');

        $this->asUser($profile->user->email)
            ->postJson('/api/v1/enrollments', ['academic_term_id' => $term->id, 'block_code' => 'IT101'])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['errors' => ['academic_term_id']]]);
    }

    public function test_the_program_chair_declining_the_last_pending_credit_issues_the_number(): void
    {
        Mail::fake();
        $profile = $this->transferee(withClosedCredit: false);
        $this->credit($profile, TransfereeCreditStatus::Approved);
        $pending = $this->credit($profile, TransfereeCreditStatus::Pending);
        $profile->program->update(['college' => CollegeCode::Ccs]);
        User::create([
            'name' => 'Test chair', 'email' => 'chair@grc.test', 'password' => self::FRESHMAN_TEST_PASSWORD,
            'role' => UserRole::ProgramChair, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
            'college' => CollegeCode::Ccs,
        ]);

        $this->asUser('chair@grc.test')
            ->patchJson("/api/v1/transferee-credits/{$pending->id}", ['action' => 'decline', 'reason' => 'No equivalent subject.'])
            ->assertOk();

        self::assertSame(1, QueueTicket::query()->where('student_profile_id', $profile->id)->count());
        Mail::assertSent(TransfereeQueueTicketMail::class, 1);
    }

    private function payAtTheCashier(StudentProfile $profile, string $amount = '1000.00'): TestResponse
    {
        return $this->asStaff(UserRole::AccountingStaff, 'cashier@grc.test')
            ->postJson("/api/v1/student-profiles/{$profile->id}/freshman-payment", ['amount' => $amount]);
    }

    public function test_the_cashier_confirming_a_transferees_first_payment_sends_their_enrollment_to_the_registrar_and_not_to_enrolled(): void
    {
        Mail::fake();
        $registrar = $this->staffUser(UserRole::RegistrarStaff, 'registrar@grc.test');
        ['profile' => $profile, 'section' => $section, 'enrollmentId' => $enrollmentId] = $this->submittedBlock();
        $this->issueFreshmanTicket($profile);

        $this->payAtTheCashier($profile)
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'awaiting_registrar_approval')
            ->assertJsonPath('data.enrollment.status', 'pending_registrar_approval');

        $enrollment = Enrollment::query()->findOrFail($enrollmentId);
        self::assertSame(EnrollmentStatus::PendingRegistrarApproval, $enrollment->status);
        self::assertNotNull($enrollment->payment_confirmed_at);
        self::assertNotNull($profile->fresh()->freshman_payment_confirmed_at);
        self::assertSame(QueueTicketStatus::Served, QueueTicket::query()->where('student_profile_id', $profile->id)->sole()->status);
        $held = AccountPayment::query()->sole();
        self::assertNull($held->enrollment_id, 'the money is held as credit until the Registrar approves');
        self::assertSame('1000.00', (string) $held->amount);
        self::assertSame(1, $section->fresh()->enrolled_count, 'the seat is the same one the hold reserved');
        self::assertSame(1, Notification::query()->where('user_id', $registrar->id)->where('type', NotificationType::FreshmanAwaitingRegistrarApproval)->count());
        $told = Notification::query()->where('user_id', $profile->user_id)->where('type', NotificationType::FreshmanAwaitingRegistrarApproval)->sole();
        self::assertStringContainsString('Registrar approval', $told->message);
        // Not enrolled and not emailed at payment; a transferee already has an account, so no setup email either.
        Mail::assertNotSent(EnrollmentConfirmedMail::class);
        Mail::assertNotSent(StudentAccountSetupMail::class);
    }

    public function test_a_transferee_who_pays_before_choosing_subjects_has_the_payment_held_and_is_enrolled_by_the_registrar_later(): void
    {
        Mail::fake();
        $profile = $this->transferee();
        $term = $this->ongoingTerm();
        $section = $this->blockSection($term, $this->yearOnePlan($term, $profile->curriculum), 'IT101', 'ITP1');
        $this->issueFreshmanTicket($profile);

        $this->payAtTheCashier($profile)
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'awaiting_section')
            ->assertJsonPath('data.enrollment', null);
        self::assertSame(0, Enrollment::query()->count());
        $told = Notification::query()->where('user_id', $profile->user_id)->where('type', NotificationType::FreshmanAwaitingSection)->sole();
        self::assertStringContainsString('choose your subjects', $told->message);

        // The student now chooses: already paid, so the submission goes straight to the Registrar.
        $enrollmentId = (int) $this->asUser($profile->user->email)
            ->postJson('/api/v1/enrollments', ['academic_term_id' => $term->id, 'block_code' => 'IT101'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_registrar_approval')
            ->json('data.id');

        $this->asStaff(UserRole::RegistrarStaff, 'registrar@grc.test')
            ->patchJson("/api/v1/enrollments/{$enrollmentId}", ['action' => 'registrar_approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'enrolled');

        self::assertSame($enrollmentId, AccountPayment::query()->sole()->enrollment_id);
        self::assertSame(1, Payment::query()->where('enrollment_id', $enrollmentId)->count());
        self::assertSame(1, EnrollmentDocument::query()->where('enrollment_id', $enrollmentId)->count());
        Mail::assertSent(EnrollmentConfirmedMail::class, 1);
        self::assertSame(1, $section->fresh()->enrolled_count);
    }

    public function test_confirming_the_same_first_payment_twice_changes_nothing_the_second_time(): void
    {
        Mail::fake();
        ['profile' => $profile] = $this->submittedBlock();
        $this->issueFreshmanTicket($profile);
        $registrar = $this->staffUser(UserRole::RegistrarStaff, 'registrar@grc.test');

        $this->payAtTheCashier($profile)->assertCreated();
        $this->payAtTheCashier($profile, '5000.00')->assertOk();

        self::assertSame(1, AccountPayment::query()->count());
        self::assertSame('1000.00', (string) AccountPayment::query()->sole()->amount);
        self::assertSame(1, Notification::query()->where('user_id', $registrar->id)->where('type', NotificationType::FreshmanAwaitingRegistrarApproval)->count());
    }

    public function test_the_first_payment_must_reach_the_configured_minimum(): void
    {
        ['profile' => $profile] = $this->submittedBlock();
        $this->issueFreshmanTicket($profile);

        $this->payAtTheCashier($profile, '999.99')
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['errors' => ['amount']]]);

        self::assertSame(0, AccountPayment::query()->count());
        self::assertNull($profile->fresh()->freshman_payment_confirmed_at);
        self::assertSame(EnrollmentStatus::AwaitingFirstPayment, Enrollment::query()->sole()->status);
    }

    public function test_a_student_who_does_not_pay_first_cannot_be_confirmed_this_way_and_only_accounting_staff_can_confirm(): void
    {
        $returnee = $this->freshmanProfile('2026-0777', StudentType::Returnee);

        $this->payAtTheCashier($returnee)->assertUnprocessable();

        ['profile' => $profile] = $this->submittedBlock();
        $this->asStaff(UserRole::RegistrarStaff, 'registrar@grc.test')
            ->postJson("/api/v1/student-profiles/{$profile->id}/freshman-payment", ['amount' => '1000.00'])
            ->assertForbidden();
        self::assertSame(0, AccountPayment::query()->count());
    }

    public function test_the_payment_preview_shows_a_transferees_chosen_subjects_or_that_none_are_chosen_yet(): void
    {
        ['profile' => $profile] = $this->submittedBlock();
        $other = $this->freshmanProfile('2026-0901', StudentType::Transferee);
        $cashier = $this->asStaff(UserRole::AccountingStaff, 'cashier@grc.test');

        $cashier->getJson("/api/v1/student-profiles/{$profile->id}/freshman-payment-preview")
            ->assertOk()
            ->assertJsonPath('data.student_type', 'transferee')
            ->assertJsonPath('data.can_enroll', true)
            ->assertJsonPath('data.block', null)
            ->assertJsonPath('data.enrollment.status', 'awaiting_first_payment')
            ->assertJsonPath('data.enrollment.subjects.0.code', 'ITP1');

        $this->asUser('cashier@grc.test')->getJson("/api/v1/student-profiles/{$other->id}/freshman-payment-preview")
            ->assertOk()
            ->assertJsonPath('data.student_type', 'transferee')
            ->assertJsonPath('data.can_enroll', false)
            ->assertJsonPath('data.enrollment', null);
    }

    public function test_the_cashiers_ticket_list_marks_a_transferee_ticket(): void
    {
        $profile = $this->transferee();
        $this->issueFreshmanTicket($profile);

        $row = $this->asStaff(UserRole::AccountingStaff, 'cashier@grc.test')
            ->getJson('/api/v1/queue-tickets')->assertOk()->json('data.0');

        self::assertTrue($row['is_transferee']);
        self::assertFalse($row['is_freshman']);
        self::assertNull($row['enrollment_id']);
    }

    public function test_a_transferee_sees_their_queue_number_before_they_have_an_enrollment_and_at_the_hold_but_not_after_paying(): void
    {
        $profile = $this->transferee();
        $term = $this->ongoingTerm();
        $this->blockSection($term, $this->yearOnePlan($term, $profile->curriculum), 'IT101', 'ITP1');
        $this->issueFreshmanTicket($profile);
        $student = fn (): array => $this->asUser($profile->user->email)->getJson('/api/v1/queue-status')->assertOk()->json('data');

        // 1. A number, no enrollment yet: the student still sees the number they were emailed.
        $view = $student();
        self::assertSame('no_active_enrollment', $view['stage']);
        self::assertSame('Q001', $view['ticket']['ticket_number']);
        self::assertFalse($view['can_claim']);

        // 2. Subjects chosen, waiting at the hold: that stage, same number.
        $this->asUser($profile->user->email)
            ->postJson('/api/v1/enrollments', ['academic_term_id' => $term->id, 'block_code' => 'IT101'])->assertCreated();
        $view = $student();
        self::assertSame('awaiting_first_payment', $view['stage']);
        self::assertSame('Q001', $view['ticket']['ticket_number']);
        self::assertFalse($view['can_claim']);

        // 3. Paid: the number has done its job, and the enrollment waits for the Registrar.
        $this->payAtTheCashier($profile)->assertCreated();
        $view = $student();
        self::assertSame('pending_registrar_approval', $view['stage']);
        self::assertNull($view['ticket']);
    }

    public function test_a_transferees_own_profile_says_the_payment_is_held_from_the_cashier_until_the_registrar_approves(): void
    {
        ['profile' => $profile, 'enrollmentId' => $enrollmentId] = $this->submittedBlock();
        $this->issueFreshmanTicket($profile);
        $own = fn (): array => $this->asUser($profile->user->email)->getJson('/api/v1/student-profile')->assertOk()->json('data');

        // Nothing paid yet.
        self::assertFalse($own()['advance_payment_held']);
        self::assertNull($own()['freshman_payment_confirmed_at']);

        // Paid: the screens must say the payment is on file, so the student is not sent to claim a kiosk ticket and pay again.
        $this->payAtTheCashier($profile)->assertCreated();
        $held = $own();
        self::assertTrue($held['advance_payment_held']);
        self::assertNotNull($held['freshman_payment_confirmed_at']);
        self::assertNull($held['freshman_status'], 'a transferee has no freshman status: they already have an account');

        // Approved: the held credit is applied, so nothing is held any more.
        $this->asStaff(UserRole::RegistrarStaff, 'registrar@grc.test')
            ->patchJson("/api/v1/enrollments/{$enrollmentId}", ['action' => 'registrar_approve'])->assertOk();
        self::assertFalse($own()['advance_payment_held']);
    }

    public function test_the_program_chairs_approval_and_the_cashiers_payment_take_their_row_locks_in_the_same_order(): void
    {
        // A Program Chair approving an Irregular transferee's schedule while the Cashier takes their payment
        // must not deadlock: both lock the student's profile first, then the enrollment.
        $profile = $this->transferee('irregular');
        $term = $this->ongoingTerm();
        $section = $this->blockSection($term, $this->yearOnePlan($term, $profile->curriculum), 'IT101', 'ITP1', ['is_block_exclusive' => false]);
        $profile->program->update(['college' => CollegeCode::Ccs]);
        User::create([
            'name' => 'Test chair', 'email' => 'chair@grc.test', 'password' => self::FRESHMAN_TEST_PASSWORD,
            'role' => UserRole::ProgramChair, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
            'college' => CollegeCode::Ccs,
        ]);
        $enrollmentId = (int) $this->asUser($profile->user->email)
            ->postJson('/api/v1/enrollments', ['academic_term_id' => $term->id, 'sections' => [['section_id' => $section->id]]])
            ->assertCreated()->json('data.id');
        $this->issueFreshmanTicket($profile);

        $lockOrder = function (callable $act): array {
            $locks = [];
            DB::listen(function ($query) use (&$locks): void {
                $sql = strtolower($query->sql);
                if (! str_contains($sql, 'for update')) {
                    return;
                }
                if (str_contains($sql, '`student_profiles`')) {
                    $locks[] = 'profile';
                } elseif (str_contains($sql, '`enrollments`')) {
                    $locks[] = 'enrollment';
                }
            });
            $act();

            return array_slice(array_values(array_unique($locks)), 0, 2);
        };

        $chair = $this->asUser('chair@grc.test');
        self::assertSame(['profile', 'enrollment'], $lockOrder(
            fn () => $chair->patchJson("/api/v1/enrollments/{$enrollmentId}", ['action' => 'program_head_approve'])->assertOk(),
        ), 'the Program Chair\'s approval');

        $cashier = $this->asStaff(UserRole::AccountingStaff, 'cashier@grc.test');
        self::assertSame(['profile', 'enrollment'], $lockOrder(
            fn () => $cashier->postJson("/api/v1/student-profiles/{$profile->id}/freshman-payment", ['amount' => '1000.00'])->assertCreated(),
        ), 'the Cashier\'s payment');
    }

    public function test_a_submission_is_routed_by_the_payment_status_read_under_lock_not_by_a_stale_profile(): void
    {
        $profile = $this->transferee();
        $term = $this->ongoingTerm();
        $section = $this->blockSection($term, $this->yearOnePlan($term, $profile->curriculum), 'IT101', 'ITP1');
        // The profile the request loaded, before the Cashier's payment lands in the window.
        $stale = StudentProfile::query()->findOrFail($profile->id);
        StudentProfile::query()->whereKey($profile->id)->update(['freshman_payment_confirmed_at' => now()]);

        $enrollment = $this->app->make(SubmitEnrollment::class)->execute(
            $stale,
            $term,
            [$section->id],
            $profile->user,
            new AuditRequestContext('test-stale-profile', null),
            'IT101',
        );

        self::assertSame(EnrollmentStatus::PendingRegistrarApproval, $enrollment->status, 'already paid, so not the payment hold');
    }

    public function test_a_caller_who_may_not_confirm_payments_gets_403_even_with_an_invalid_amount(): void
    {
        ['profile' => $profile] = $this->submittedBlock();

        $this->asStaff(UserRole::RegistrarStaff, 'registrar@grc.test')
            ->postJson("/api/v1/student-profiles/{$profile->id}/freshman-payment", ['amount' => 'not-a-number'])
            ->assertForbidden();
    }

    public function test_the_whole_transferee_journey_from_the_heads_approval_to_enrolled(): void
    {
        Mail::fake();
        $profile = $this->transferee(withClosedCredit: false);
        $this->credit($profile, TransfereeCreditStatus::Endorsed);
        $profile->forceFill(['enrollment_deadline_at' => now()->addDays(5)])->save();
        $term = $this->ongoingTerm();
        $section = $this->blockSection($term, $this->yearOnePlan($term, $profile->curriculum), 'IT101', 'ITP1');

        // 1. The Registrar Head approves the last credit: the queue number is issued and emailed.
        $this->asStaff(UserRole::RegistrarHead, 'head@grc.test')
            ->postJson("/api/v1/student-profiles/{$profile->id}/transferee-credits/approve-all")->assertOk();
        $ticket = QueueTicket::query()->where('student_profile_id', $profile->id)->sole();
        Mail::assertSent(TransfereeQueueTicketMail::class, 1);

        // 2. The student chooses subjects: they wait at the hold, the Registrar is not told.
        $enrollmentId = (int) $this->asUser($profile->user->email)
            ->postJson('/api/v1/enrollments', ['academic_term_id' => $term->id, 'block_code' => 'IT101'])
            ->assertCreated()->assertJsonPath('data.status', 'awaiting_first_payment')->json('data.id');

        // 3. The Cashier takes the first payment: it moves to the Registrar, the ticket is served.
        $this->payAtTheCashier($profile)->assertCreated()->assertJsonPath('data.outcome', 'awaiting_registrar_approval');
        self::assertSame(QueueTicketStatus::Served, $ticket->fresh()->status);

        // Paid, but not enrolled yet: the portal stays restricted and the deactivation notice stays.
        self::assertNotNull($profile->fresh()->enrollment_deadline_at);

        // 4. Registrar Staff approve: enrolled from the held payment, COR issued, one enrolled email.
        $this->asStaff(UserRole::RegistrarStaff, 'registrar@grc.test')
            ->patchJson("/api/v1/enrollments/{$enrollmentId}", ['action' => 'registrar_approve'])
            ->assertOk()->assertJsonPath('data.status', 'enrolled');

        // Enrolled and paid: now the other options open and the deadline is gone (owner, 2026-10-15).
        self::assertNull($profile->fresh()->enrollment_deadline_at);

        self::assertSame(EnrollmentStatus::Enrolled, Enrollment::query()->findOrFail($enrollmentId)->status);
        self::assertSame($enrollmentId, AccountPayment::query()->sole()->enrollment_id);
        self::assertSame(1, Payment::query()->where('enrollment_id', $enrollmentId)->count());
        self::assertSame(1, EnrollmentDocument::query()->where('enrollment_id', $enrollmentId)->count());
        Mail::assertSent(EnrollmentConfirmedMail::class, fn (EnrollmentConfirmedMail $mail): bool => $mail->hasTo($profile->user->email));
        self::assertSame(1, $section->fresh()->enrolled_count);
    }

    public function test_a_mail_failure_never_undoes_the_ticket_or_the_approval(): void
    {
        $profile = $this->transferee(withClosedCredit: false);
        $this->credit($profile, TransfereeCreditStatus::Endorsed);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp is down'));

        $this->asStaff(UserRole::RegistrarHead, 'head@grc.test')
            ->postJson("/api/v1/student-profiles/{$profile->id}/transferee-credits/approve-all")
            ->assertOk();

        $ticket = QueueTicket::query()->where('student_profile_id', $profile->id)->sole();
        self::assertNull($ticket->link_sent_at);
        self::assertNotNull($ticket->link_failed_at);
        self::assertSame(1, TransfereeCredit::query()->where('student_id', $profile->id)->where('status', TransfereeCreditStatus::Approved)->count());
    }

    public function test_the_registrars_approval_alone_keeps_the_enrollment_deadline_until_the_student_is_enrolled_and_paid(): void
    {
        // Paid, but no payment is held as credit, so the approval leaves the enrollment awaiting payment.
        ['profile' => $profile, 'enrollmentId' => $enrollmentId] = $this->submittedBlock(paid: true);
        $deadline = now()->addDays(3)->startOfSecond();
        $profile->forceFill(['enrollment_deadline_at' => $deadline])->save();

        $this->asStaff(UserRole::RegistrarStaff, 'registrar@grc.test')
            ->patchJson("/api/v1/enrollments/{$enrollmentId}", ['action' => 'registrar_approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_payment');

        self::assertEquals($deadline, $profile->fresh()->enrollment_deadline_at, 'not enrolled yet: still restricted');
    }

    public function test_the_registrars_rejection_keeps_the_enrollment_deadline(): void
    {
        ['profile' => $profile, 'enrollmentId' => $enrollmentId] = $this->submittedBlock(paid: true);
        $deadline = now()->addDays(3)->startOfSecond();
        $profile->forceFill(['enrollment_deadline_at' => $deadline])->save();

        $this->asStaff(UserRole::RegistrarStaff, 'registrar@grc.test')
            ->patchJson("/api/v1/enrollments/{$enrollmentId}", ['action' => 'registrar_reject', 'reason' => 'Missing documents.'])->assertOk();

        self::assertEquals($deadline, $profile->fresh()->enrollment_deadline_at, 'a rejection is not an approval');
    }

    public function test_the_registrars_approval_takes_the_profile_lock_before_the_enrollment_lock(): void
    {
        ['profile' => $profile, 'enrollmentId' => $enrollmentId] = $this->submittedBlock(paid: true);
        $profile->forceFill(['enrollment_deadline_at' => now()->addDays(3)])->save();
        $registrar = $this->asStaff(UserRole::RegistrarStaff, 'registrar@grc.test');

        $locks = [];
        DB::listen(function ($query) use (&$locks): void {
            $sql = strtolower($query->sql);
            if (! str_contains($sql, 'for update')) {
                return;
            }
            if (str_contains($sql, '`student_profiles`')) {
                $locks[] = 'profile';
            } elseif (str_contains($sql, '`enrollments`')) {
                $locks[] = 'enrollment';
            }
        });
        $registrar->patchJson("/api/v1/enrollments/{$enrollmentId}", ['action' => 'registrar_approve'])->assertOk();

        self::assertSame(['profile', 'enrollment'], array_slice(array_values(array_unique($locks)), 0, 2));
    }
}
