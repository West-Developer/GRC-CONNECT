<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Enrollment\QueueTicketPriority;
use App\Domain\Enrollment\QueueTicketStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Notifications\NotificationType;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Mail\EnrollmentConfirmedMail;
use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\AssessmentItem;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\EnrollmentDocument;
use App\Models\EnrollmentSubject;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Program;
use App\Models\QueueCycle;
use App\Models\QueueTicket;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class PaymentConfirmationEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function makeTerm(): AcademicTerm
    {
        return AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
    }

    private function makeCurriculum(): Curriculum
    {
        $program = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);

        return Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
    }

    private function makeStudent(Curriculum $curriculum): StudentProfile
    {
        $user = User::create([
            'name' => 'Test Student', 'email' => 'student.payment@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => '2026-0001',
            'program_id' => $curriculum->program_id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
    }

    private function makeEnrollment(StudentProfile $student, AcademicTerm $term, EnrollmentStatus $status = EnrollmentStatus::PendingPayment): Enrollment
    {
        return Enrollment::create([
            'student_id' => $student->id,
            'academic_term_id' => $term->id,
            'status' => $status,
            'total_units' => 3,
            'submitted_at' => now(),
        ]);
    }

    private function makeAssessment(Enrollment $enrollment, string $totalAmount): Assessment
    {
        return Assessment::create([
            'enrollment_id' => $enrollment->id,
            'total_amount' => $totalAmount,
            'currency' => 'PHP',
            'assessed_at' => now(),
        ]);
    }

    private function tokenForNewUser(UserRole $role, string $email): string
    {
        User::create([
            'name' => 'Test '.$role->value, 'email' => $email,
            'password' => self::PASSWORD, 'role' => $role, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->postJson('/api/v1/enrollments/1/payment', [])->assertUnauthorized();
    }

    public function test_a_non_accounting_role_cannot_confirm_payment(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term);
        $token = $this->tokenForNewUser(UserRole::RegistrarHead, 'registrar.paymentforbidden@grc.test');

        $response = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/payment", []);

        $response->assertForbidden();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_accounting_staff_can_adjust_a_pending_assessment_before_payment_and_cor_generation(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term);
        $assessment = $this->makeAssessment($enrollment, '800.00');
        $tuition = AssessmentItem::create([
            'assessment_id' => $assessment->id,
            'category' => 'tuition',
            'label' => 'Tuition',
            'quantity' => '3.0',
            'unit_amount' => '200.00',
            'amount' => '600.00',
        ]);
        $registration = AssessmentItem::create([
            'assessment_id' => $assessment->id,
            'category' => 'miscellaneous',
            'label' => 'Registration',
            'quantity' => null,
            'unit_amount' => null,
            'amount' => '200.00',
        ]);
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.assessment-adjust@grc.test');

        $response = $this->withToken($token)->patchJson("/api/v1/enrollments/{$enrollment->id}/assessment", [
            'reason' => 'Applied approved student fee adjustment.',
            'items' => [
                ['id' => $tuition->id, 'unit_amount' => '250.00'],
                ['id' => $registration->id, 'amount' => '300.00'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.assessment.total_amount', '1050.00')
            ->assertJsonPath('data.assessment.items.0.unit_amount', '250.00')
            ->assertJsonPath('data.assessment.items.0.amount', '750.00')
            ->assertJsonPath('data.assessment.items.1.amount', '300.00');
        $this->assertDatabaseHas('assessments', ['id' => $assessment->id, 'total_amount' => '1050.00']);
        self::assertSame('250.00', $tuition->refresh()->unit_amount);
        self::assertSame('750.00', $tuition->amount);
        self::assertSame('300.00', $registration->refresh()->amount);
        self::assertSame(AuditAction::ASSESSMENT_ADJUSTED, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole()->action);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('enrollment_documents', 0);
    }

    public function test_a_fee_assessment_cannot_be_adjusted_after_payment_has_created_the_cor(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term);
        $assessment = $this->makeAssessment($enrollment, '600.00');
        $tuition = AssessmentItem::create([
            'assessment_id' => $assessment->id,
            'category' => 'tuition',
            'label' => 'Tuition',
            'quantity' => '3.0',
            'unit_amount' => '200.00',
            'amount' => '600.00',
        ]);
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.assessment-locked@grc.test');
        $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/payment", [])->assertCreated();

        $response = $this->withToken($token)->patchJson("/api/v1/enrollments/{$enrollment->id}/assessment", [
            'reason' => 'This must not alter an issued certificate.',
            'items' => [['id' => $tuition->id, 'unit_amount' => '250.00']],
        ]);

        $response->assertUnprocessable();
        self::assertSame('200.00', $tuition->refresh()->unit_amount);
        self::assertSame('600.00', $assessment->refresh()->total_amount);
    }

    /**
     * Owner, 2026-10-15: a new Transferee or Returnee keeps the restricted portal and the 7-day
     * deactivation notice until the enrollment is finished AND paid, so the payment that makes the
     * student `enrolled` is what removes the deadline (it used to go at the Registrar's approval).
     */
    public function test_the_payment_that_enrols_the_student_removes_the_enrollment_deadline(): void
    {
        Mail::fake();
        $student = $this->makeStudent($this->makeCurriculum());
        $enrollment = $this->makeEnrollment($student, $this->makeTerm());
        $student->forceFill(['enrollment_deadline_at' => now()->addDays(3)])->save();

        $this->withToken($this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.deadline@grc.test'))
            ->postJson("/api/v1/enrollments/{$enrollment->id}/payment", ['amount' => 1500.00])
            ->assertCreated();

        self::assertNull($student->fresh()->enrollment_deadline_at, 'finished and paid: the portal opens for good');
    }

    public function test_the_registrars_approval_of_an_unpaid_enrollment_keeps_the_enrollment_deadline(): void
    {
        $student = $this->makeStudent($this->makeCurriculum());
        $enrollment = $this->makeEnrollment($student, $this->makeTerm(), EnrollmentStatus::PendingRegistrarApproval);
        $deadline = now()->addDays(3)->startOfSecond();
        $student->forceFill(['enrollment_deadline_at' => $deadline])->save();

        $this->withToken($this->tokenForNewUser(UserRole::RegistrarStaff, 'registrar.deadline@grc.test'))
            ->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'registrar_approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_payment');

        self::assertEquals($deadline, $student->fresh()->enrollment_deadline_at, 'approved but not yet paid: still restricted');
    }

    public function test_a_payment_with_no_deadline_to_remove_changes_nothing_on_the_profile(): void
    {
        Mail::fake();
        $student = $this->makeStudent($this->makeCurriculum());
        $enrollment = $this->makeEnrollment($student, $this->makeTerm());

        $this->withToken($this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.nodeadline@grc.test'))
            ->postJson("/api/v1/enrollments/{$enrollment->id}/payment", ['amount' => 1500.00])
            ->assertCreated();

        self::assertNull($student->fresh()->enrollment_deadline_at);
    }

    public function test_the_payment_takes_the_profile_lock_before_the_enrollment_lock(): void
    {
        Mail::fake();
        $student = $this->makeStudent($this->makeCurriculum());
        $enrollment = $this->makeEnrollment($student, $this->makeTerm());
        $student->forceFill(['enrollment_deadline_at' => now()->addDays(3)])->save();
        $cashier = $this->withToken($this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.locks@grc.test'));

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
        $cashier->postJson("/api/v1/enrollments/{$enrollment->id}/payment", ['amount' => 1500.00])->assertCreated();

        self::assertSame(['profile', 'enrollment'], array_slice(array_values(array_unique($locks)), 0, 2));
    }

    public function test_confirming_payment_transitions_enrollment_and_generates_cor(): void
    {
        Mail::fake();
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term);
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.confirm@grc.test');

        $response = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/payment", [
            'external_reference' => 'OR-000123',
            'amount' => 1500.00,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.enrollment.status', 'enrolled');
        $response->assertJsonPath('data.payment.external_reference', 'OR-000123');
        $response->assertJsonPath('data.document.document_type', 'cor');
        $response->assertJsonPath('data.document.document_number', sprintf('COR%06d', $enrollment->id));

        self::assertSame('enrolled', $enrollment->refresh()->status->value);
        self::assertNotNull($enrollment->payment_confirmed_at);
        self::assertNotNull($enrollment->enrolled_at);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('enrollment_documents', 1);
        self::assertSame(
            'Certificate of Registration',
            EnrollmentDocument::query()->sole()->snapshot['document_title'],
        );
        self::assertEqualsCanonicalizing(
            [AuditAction::ENROLLMENT_PAYMENT_CONFIRMED, AuditAction::ENROLLMENT_CONFIRMATION_EMAIL_SENT],
            AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->pluck('action')->all(),
        );
        self::assertSame(NotificationType::EnrollmentPaymentConfirmed, Notification::query()->sole()->type);
        Mail::assertSent(
            EnrollmentConfirmedMail::class,
            fn (EnrollmentConfirmedMail $mail): bool => $mail->hasTo($student->user->email)
                && $mail->documentNumber === sprintf('COR%06d', $enrollment->id),
        );
    }

    public function test_confirming_payment_moves_selected_subjects_to_enrolled_but_leaves_dropped_ones_alone(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term);
        $subject = Subject::create(['code' => 'CS101', 'title' => 'CS101 Title', 'units' => 3.0, 'status' => SubjectStatus::Active]);
        $otherSubject = Subject::create(['code' => 'CS102', 'title' => 'CS102 Title', 'units' => 3.0, 'status' => SubjectStatus::Active]);
        $section = Section::create([
            'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'section_code' => 'A',
            'capacity' => 40, 'status' => SectionStatus::Published,
        ]);
        $droppedSection = Section::create([
            'academic_term_id' => $term->id, 'subject_id' => $otherSubject->id, 'section_code' => 'A',
            'capacity' => 40, 'status' => SectionStatus::Published,
        ]);
        $selectedSubject = EnrollmentSubject::create([
            'enrollment_id' => $enrollment->id, 'section_id' => $section->id, 'status' => EnrollmentSubjectStatus::Selected,
        ]);
        $droppedSubject = EnrollmentSubject::create([
            'enrollment_id' => $enrollment->id, 'section_id' => $droppedSection->id, 'status' => EnrollmentSubjectStatus::Dropped,
        ]);
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.rostertransition@grc.test');

        $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/payment", [])->assertCreated();

        self::assertSame(EnrollmentSubjectStatus::Enrolled, $selectedSubject->refresh()->status);
        self::assertSame(EnrollmentSubjectStatus::Dropped, $droppedSubject->refresh()->status);
    }

    public function test_confirming_payment_is_idempotent_and_creates_no_duplicate(): void
    {
        Mail::fake();
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term);
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.idempotent@grc.test');

        $first = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/payment", [
            'external_reference' => 'OR-000123',
            'promissory_note_on_file' => true,
        ]);
        $first->assertCreated();

        $second = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/payment", [
            'external_reference' => 'OR-999999',
            'promissory_note_on_file' => false,
        ]);

        $second->assertOk();
        $second->assertJsonPath('data.payment.external_reference', 'OR-000123');
        $second->assertJsonPath('data.payment.promissory_note_on_file', true);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('enrollment_documents', 1);
        self::assertSame(2, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
        $this->assertDatabaseCount('notifications', 1);
        self::assertSame(Payment::query()->sole()->external_reference, 'OR-000123');
        self::assertTrue(Payment::query()->sole()->promissory_note_on_file);
        self::assertSame(EnrollmentDocument::query()->count(), 1);
        // The repeat/idempotent second call must never resend the email.
        Mail::assertSentTimes(EnrollmentConfirmedMail::class, 1);
    }

    public function test_an_omitted_amount_defaults_to_the_assessed_total(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term);
        $this->makeAssessment($enrollment, '2400.00');
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.defaultamount@grc.test');

        $response = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/payment", [
            'external_reference' => 'OR-000456',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.payment.amount', '2400.00');
        self::assertSame('2400.00', Payment::query()->sole()->amount);
    }

    public function test_an_explicit_amount_is_recorded_as_is_even_when_it_differs_from_the_assessment(): void
    {
        // §17 has no partial-payment or mismatch policy — an amount the
        // cashier actually typed is trusted, never rejected against the
        // assessment.
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term);
        $this->makeAssessment($enrollment, '2400.00');
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.explicitamount@grc.test');

        $response = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/payment", [
            'amount' => 2000.00,
            'promissory_note_on_file' => true,
        ]);

        $response->assertCreated();
        self::assertSame('2000.00', Payment::query()->sole()->amount);
    }

    public function test_an_explicit_enrollment_payment_below_1000_is_rejected_without_side_effects(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term);
        $this->makeAssessment($enrollment, '2400.00');
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.belowminimum@grc.test');

        $response = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/payment", [
            'amount' => 999.99,
        ]);

        $response->assertUnprocessable()->assertJsonPath(
            'error.errors.amount.0',
            'The amount field must be at least 1000.',
        );
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('enrollment_documents', 0);
        self::assertSame(EnrollmentStatus::PendingPayment, $enrollment->refresh()->status);
    }

    public function test_a_1000_enrollment_payment_with_a_promissory_note_finalizes_and_generates_cor(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term);
        $this->makeAssessment($enrollment, '2400.00');
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.promissory@grc.test');

        $response = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/payment", [
            'amount' => 1000,
            'promissory_note_on_file' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.enrollment.status', 'enrolled')
            ->assertJsonPath('data.payment.amount', '1000.00')
            ->assertJsonPath('data.payment.promissory_note_on_file', true)
            ->assertJsonPath('data.document.document_type', 'cor');
        $this->assertDatabaseHas('payments', [
            'enrollment_id' => $enrollment->id,
            'amount' => '1000.00',
            'promissory_note_on_file' => true,
        ]);
        $this->assertDatabaseCount('enrollment_documents', 1);
    }

    /**
     * A bill from an earlier term that is still unpaid: an enrolled, archived-term enrollment assessed
     * `$assessed` of which `$paid` was received. The student's account then carries `$assessed - $paid`
     * as a previous balance (owner, 2026-10-13: a promissory note is only for a student with a previous bill).
     */
    private function makePreviousBill(StudentProfile $student, string $assessed, string $paid): Enrollment
    {
        $earlier = AcademicTerm::create([
            'school_year' => '2025-2026', 'semester' => '2nd', 'status' => AcademicTermStatus::Archived,
        ]);
        $previous = $this->makeEnrollment($student, $earlier, EnrollmentStatus::Enrolled);
        Assessment::create([
            'enrollment_id' => $previous->id,
            'total_amount' => $assessed,
            'currency' => 'PHP',
            'assessed_at' => now()->subMonths(8),
        ]);
        Payment::create([
            'enrollment_id' => $previous->id,
            'confirmed_by' => $student->user_id,
            'amount' => $paid,
            'promissory_note_on_file' => false,
            'confirmed_at' => now()->subMonths(8),
        ]);

        return $previous;
    }

    public function test_a_partial_payment_needs_no_promissory_note_when_the_student_has_no_previous_bill(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term);
        $this->makeAssessment($enrollment, '10500.00');
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.nopromissory@grc.test');

        // The Cashier screenshot case: 10,400 of 10,500 paid, nothing owed from before, so no note.
        $response = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/payment", [
            'amount' => 10400,
            'promissory_note_on_file' => false,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.enrollment.status', 'enrolled')
            ->assertJsonPath('data.payment.amount', '10400.00')
            ->assertJsonPath('data.payment.promissory_note_on_file', false);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('enrollment_documents', 1);
    }

    public function test_a_downpayment_of_1000_needs_no_promissory_note_when_the_student_has_no_previous_bill(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term);
        $this->makeAssessment($enrollment, '2400.00');
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.downpayment@grc.test');

        $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/payment", ['amount' => 1000])
            ->assertCreated()
            ->assertJsonPath('data.payment.amount', '1000.00')
            ->assertJsonPath('data.payment.promissory_note_on_file', false);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_a_partial_payment_without_a_promissory_note_is_rejected_when_the_student_has_a_previous_bill(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $this->makePreviousBill($student, '5000.00', '1000.00');
        $enrollment = $this->makeEnrollment($student, $term);
        $this->makeAssessment($enrollment, '2400.00');
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.previousbill@grc.test');

        $response = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/payment", [
            'amount' => 1000,
            'promissory_note_on_file' => false,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.errors.promissory_note_on_file.0', 'A promissory note on file is required for partial payments.');
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('enrollment_documents', 0);
        self::assertSame(EnrollmentStatus::PendingPayment, $enrollment->refresh()->status);
    }

    public function test_a_partial_payment_with_a_promissory_note_is_accepted_when_the_student_has_a_previous_bill(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $this->makePreviousBill($student, '5000.00', '1000.00');
        $enrollment = $this->makeEnrollment($student, $term);
        $this->makeAssessment($enrollment, '2400.00');
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.previousbillnote@grc.test');

        $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/payment", [
            'amount' => 1000,
            'promissory_note_on_file' => true,
        ])->assertCreated()
            ->assertJsonPath('data.payment.promissory_note_on_file', true);
    }

    public function test_paying_the_whole_term_needs_no_promissory_note_even_with_a_previous_bill(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $this->makePreviousBill($student, '5000.00', '1000.00');
        $enrollment = $this->makeEnrollment($student, $term);
        $this->makeAssessment($enrollment, '2400.00');
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.wholeterm@grc.test');

        $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/payment", ['amount' => 2400])
            ->assertCreated()
            ->assertJsonPath('data.payment.amount', '2400.00');
    }

    public function test_a_fully_settled_previous_term_does_not_count_as_a_previous_bill(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $this->makePreviousBill($student, '5000.00', '5000.00');
        $enrollment = $this->makeEnrollment($student, $term);
        $this->makeAssessment($enrollment, '2400.00');
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.settled@grc.test');

        $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/payment", ['amount' => 1000])
            ->assertCreated()
            ->assertJsonPath('data.payment.promissory_note_on_file', false);
    }

    public function test_a_previous_bill_paid_off_by_an_account_payment_does_not_need_a_promissory_note(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $previous = $this->makePreviousBill($student, '5000.00', '1000.00');
        // The Cashier later settled the rest of that term through "Record Payment".
        $student->accountPayments()->create([
            'enrollment_id' => $previous->id,
            'received_by' => $student->user_id,
            'amount' => '4000.00',
            'received_at' => now()->subMonth(),
        ]);
        $enrollment = $this->makeEnrollment($student, $term);
        $this->makeAssessment($enrollment, '2400.00');
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.accountpaid@grc.test');

        $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/payment", ['amount' => 1000])
            ->assertCreated();
    }

    public function test_an_omitted_amount_with_no_assessment_stays_null(): void
    {
        // The legacy path: an enrollment created directly (not through
        // registrar_approve, as every fixture in this suite still does) has
        // no assessment to default from.
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term);
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.noassessment@grc.test');

        $response = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/payment", []);

        $response->assertCreated();
        self::assertNull(Payment::query()->sole()->amount);
    }

    public function test_payment_cannot_be_confirmed_from_a_non_pending_payment_status(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term, EnrollmentStatus::PendingRegistrarApproval);
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.wrongstate@grc.test');

        $response = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/payment", []);

        $response->assertUnprocessable();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_confirming_payment_completes_active_queue_ticket_and_removes_from_waiting_list(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $enrollment = $this->makeEnrollment($student, $term);
        $cycle = QueueCycle::create(['opened_on' => '2026-08-01', 'last_ticket_sequence' => 1]);
        $ticket = QueueTicket::create([
            'enrollment_id' => $enrollment->id,
            'queue_cycle_id' => $cycle->id,
            'ticket_sequence' => 1,
            'ticket_number' => 'Q001',
            'queue_date' => '2026-08-01',
            'status' => QueueTicketStatus::Waiting,
            'priority' => QueueTicketPriority::Regular,
        ]);
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.queueremove@grc.test');

        $response = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/payment", []);
        $response->assertCreated();

        self::assertSame(QueueTicketStatus::Served, $ticket->refresh()->status);
        self::assertNotNull($ticket->served_at);

        $listResponse = $this->withToken($token)->getJson('/api/v1/queue-tickets?status=waiting');
        $listResponse->assertOk();
        $listResponse->assertJsonCount(0, 'data');
    }
}
