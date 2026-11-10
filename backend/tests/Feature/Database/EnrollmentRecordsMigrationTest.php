<?php

namespace Tests\Feature\Database;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Enrollment\EnrollmentDocumentType;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\AssessmentItem;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\EnrollmentDocument;
use App\Models\EnrollmentSubject;
use App\Models\Payment;
use App\Models\Program;
use App\Models\QueueCycle;
use App\Models\QueueTicket;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class EnrollmentRecordsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollment_record_tables_have_the_prd_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('enrollments', [
            'id', 'student_id', 'academic_term_id', 'status', 'total_units',
            'submitted_at', 'registrar_decided_at', 'payment_confirmed_at',
            'enrolled_at', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('academic_grades', [
            'id', 'student_id', 'subject_id', 'section_id', 'academic_term_id',
            'final_grade', 'remarks', 'status', 'encoded_by', 'submitted_at',
            'locked_at',
        ]));

        $this->assertTrue(Schema::hasColumns('queue_tickets', [
            'id', 'enrollment_id', 'ticket_number', 'queue_date', 'status', 'priority', 'served_at', 'served_by', 'requeued_at',
        ]));

        $this->assertTrue(Schema::hasColumns('payments', [
            'id', 'enrollment_id', 'confirmed_by', 'external_reference',
            'amount', 'promissory_note_on_file', 'confirmed_at',
        ]));

        $this->assertTrue(Schema::hasColumns('account_payments', [
            'id', 'student_id', 'enrollment_id', 'received_by', 'amount',
            'received_at', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('assessments', [
            'id', 'enrollment_id', 'total_amount', 'currency', 'assessed_at',
        ]));

        $this->assertTrue(Schema::hasColumns('assessment_items', [
            'id', 'assessment_id', 'category', 'label', 'quantity',
            'unit_amount', 'amount',
        ]));

        $this->assertTrue(Schema::hasColumns('enrollment_documents', [
            'id', 'enrollment_id', 'document_type', 'document_number',
            'storage_path', 'content_hash', 'snapshot', 'generated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('student_profiles', [
            'id', 'user_id', 'student_number', 'program_id', 'curriculum_id',
            'year_level', 'admission_status', 'academic_standing', 'financial_status',
        ]));
    }

    /**
     * PRD §10.3 requires a unique ACTIVE enrollment per student and term.
     */
    public function test_a_student_cannot_hold_two_active_enrollments_in_one_term(): void
    {
        $student = $this->makeStudent();
        $term = $this->makeTerm();

        $this->makeEnrollment($student, $term, EnrollmentStatus::Draft);

        $this->expectException(QueryException::class);

        $this->makeEnrollment($student, $term, EnrollmentStatus::PendingRegistrarApproval);
    }

    /**
     * The other half of the same rule: a terminal enrollment releases the seat,
     * so a cancelled or withdrawn student may enroll again in the same term.
     * A plain unique(student_id, academic_term_id) would wrongly forbid this.
     */
    public function test_a_student_may_enroll_again_after_a_terminal_enrollment(): void
    {
        $student = $this->makeStudent();
        $term = $this->makeTerm();

        $first = $this->makeEnrollment($student, $term, EnrollmentStatus::Draft);
        $first->update(['status' => EnrollmentStatus::Cancelled]);

        $second = $this->makeEnrollment($student, $term, EnrollmentStatus::Draft);
        $second->update(['status' => EnrollmentStatus::Withdrawn]);

        $third = $this->makeEnrollment($student, $term, EnrollmentStatus::Enrolled);

        $this->assertSame(3, Enrollment::where('student_id', $student->id)->count());
        $this->assertSame(
            1,
            Enrollment::where('student_id', $student->id)->active()->count(),
            'Exactly one enrollment should still hold the seat.',
        );
        $this->assertNotNull($third->refresh()->active_academic_term_id);
        $this->assertNull($first->refresh()->active_academic_term_id);
    }

    public function test_the_same_section_cannot_be_added_to_one_enrollment_twice(): void
    {
        $enrollment = $this->makeEnrollment($this->makeStudent(), $this->makeTerm(), EnrollmentStatus::Draft);
        $section = $this->makeSection($enrollment->academicTerm);

        EnrollmentSubject::create([
            'enrollment_id' => $enrollment->id,
            'section_id' => $section->id,
            'status' => EnrollmentSubjectStatus::Enrolled,
        ]);

        $this->expectException(QueryException::class);

        EnrollmentSubject::create([
            'enrollment_id' => $enrollment->id,
            'section_id' => $section->id,
            'status' => EnrollmentSubjectStatus::Enrolled,
        ]);
    }

    /**
     * PRD §5.3 FR-FIN-009 requires payment confirmation to be idempotent. The
     * unique constraint is what makes a duplicate impossible rather than merely
     * unlikely.
     */
    /**
     * The daily-reset queue (see the 2026_08_06_000003 migration's own
     * docblock) needs `ticket_number` to repeat across days, so the unique
     * constraint moved from a bare `ticket_number` to the composite
     * `(queue_date, ticket_number)` — still enough to make a same-day
     * duplicate impossible.
     */
    public function test_the_same_ticket_number_is_allowed_on_different_days_but_not_the_same_day(): void
    {
        // One student, three different terms — sidesteps the
        // one-active-enrollment-per-student-per-term constraint tested
        // above, since this test is only exercising queue_tickets' own
        // constraint, not enrollments'.
        $student = $this->makeStudent();
        $enrollmentA = $this->makeEnrollment($student, $this->makeTerm(), EnrollmentStatus::PendingPayment);
        $enrollmentB = $this->makeEnrollment($student, AcademicTerm::create([
            'school_year' => '2025-2026', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
        ]), EnrollmentStatus::PendingPayment);

        // All three tickets in this test are created in one run without any
        // cut-off/close between them, so they can all share one cycle.
        $cycle = QueueCycle::create(['opened_on' => '2026-08-01', 'last_ticket_sequence' => 0]);

        QueueTicket::create([
            'enrollment_id' => $enrollmentA->id, 'queue_cycle_id' => $cycle->id, 'ticket_sequence' => 1,
            'ticket_number' => 'Q001', 'queue_date' => '2026-08-01', 'status' => 'waiting',
        ]);

        // Same ticket number, a different day — allowed.
        QueueTicket::create([
            'enrollment_id' => $enrollmentB->id, 'queue_cycle_id' => $cycle->id, 'ticket_sequence' => 2,
            'ticket_number' => 'Q001', 'queue_date' => '2026-08-02', 'status' => 'waiting',
        ]);
        $this->assertDatabaseCount('queue_tickets', 2);

        // Same ticket number, the same day — rejected.
        $enrollmentC = $this->makeEnrollment($student, AcademicTerm::create([
            'school_year' => '2024-2025', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
        ]), EnrollmentStatus::PendingPayment);
        $this->expectException(QueryException::class);
        QueueTicket::create([
            'enrollment_id' => $enrollmentC->id, 'queue_cycle_id' => $cycle->id, 'ticket_sequence' => 3,
            'ticket_number' => 'Q001', 'queue_date' => '2026-08-01', 'status' => 'waiting',
        ]);
    }

    public function test_an_enrollment_cannot_have_two_payments(): void
    {
        $enrollment = $this->makeEnrollment($this->makeStudent(), $this->makeTerm(), EnrollmentStatus::PendingPayment);
        $accounting = $this->makeUser(UserRole::AccountingStaff, 'accounting');

        $payment = [
            'enrollment_id' => $enrollment->id,
            'confirmed_by' => $accounting->id,
            'external_reference' => 'OR-1',
            'confirmed_at' => now(),
        ];

        Payment::create($payment);

        $this->expectException(QueryException::class);

        Payment::create($payment);
    }

    /**
     * PRD §5.3 process 3.3 "computes the approved assessment" — this Action
     * is meant to run exactly once per enrollment (see
     * App\Actions\Billing\AssessEnrollment's own idempotency check). The
     * unique constraint is the same belt-and-suspenders guarantee
     * `test_an_enrollment_cannot_have_two_payments` above already relies on.
     */
    public function test_an_enrollment_cannot_have_two_assessments(): void
    {
        $enrollment = $this->makeEnrollment($this->makeStudent(), $this->makeTerm(), EnrollmentStatus::PendingPayment);

        $assessment = [
            'enrollment_id' => $enrollment->id,
            'total_amount' => '2400.00',
            'currency' => 'PHP',
            'assessed_at' => now(),
        ];

        Assessment::create($assessment);

        $this->expectException(QueryException::class);

        Assessment::create($assessment);
    }

    public function test_an_assessment_item_belongs_to_its_assessment(): void
    {
        $enrollment = $this->makeEnrollment($this->makeStudent(), $this->makeTerm(), EnrollmentStatus::PendingPayment);
        $assessment = Assessment::create([
            'enrollment_id' => $enrollment->id,
            'total_amount' => '2400.00',
            'currency' => 'PHP',
            'assessed_at' => now(),
        ]);

        $item = AssessmentItem::create([
            'assessment_id' => $assessment->id,
            'category' => 'tuition',
            'label' => 'Tuition',
            'quantity' => '3.0',
            'unit_amount' => '450.00',
            'amount' => '1350.00',
        ]);

        $this->assertSame($assessment->id, $item->refresh()->assessment_id);
        $this->assertCount(1, $assessment->items()->get());
    }

    public function test_an_enrollment_cannot_have_two_documents_of_the_same_type(): void
    {
        $enrollment = $this->makeEnrollment($this->makeStudent(), $this->makeTerm(), EnrollmentStatus::Enrolled);

        EnrollmentDocument::create([
            'enrollment_id' => $enrollment->id,
            'document_type' => EnrollmentDocumentType::Cor,
            'document_number' => 'COR-0001',
            'generated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        EnrollmentDocument::create([
            'enrollment_id' => $enrollment->id,
            'document_type' => EnrollmentDocumentType::Cor,
            'document_number' => 'COR-0002',
            'generated_at' => now(),
        ]);
    }

    private function makeUser(UserRole $role, string $handle): User
    {
        return User::create([
            'name' => 'Test '.$role->value,
            'email' => $handle.'@grc.test',
            'password' => 'irrelevant-password',
            'role' => $role,
            'status' => UserStatus::Active,
        ]);
    }

    private function makeStudent(): StudentProfile
    {
        $program = Program::create([
            'code' => 'BSCS',
            'name' => 'BS Computer Science',
            'status' => ProgramStatus::Active,
        ]);

        $curriculum = Curriculum::create([
            'program_id' => $program->id,
            'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027',
            'status' => CurriculumStatus::Active,
        ]);

        return StudentProfile::create([
            'user_id' => $this->makeUser(UserRole::Student, 'student')->id,
            'student_number' => 'STU-0001',
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
    }

    private function makeTerm(): AcademicTerm
    {
        return AcademicTerm::create([
            'school_year' => '2026-2027',
            'semester' => '1st',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
    }

    private function makeSection(AcademicTerm $term): Section
    {
        $subject = Subject::create([
            'code' => 'CS101',
            'title' => 'Introduction to Computing',
            'units' => 3,
            'status' => SubjectStatus::Active,
        ]);

        return Section::create([
            'academic_term_id' => $term->id,
            'subject_id' => $subject->id,
            'section_code' => 'A',
            'capacity' => 40,
            'status' => SectionStatus::Published,
        ]);
    }

    private function makeEnrollment(
        StudentProfile $student,
        AcademicTerm $term,
        EnrollmentStatus $status,
    ): Enrollment {
        return Enrollment::create([
            'student_id' => $student->id,
            'academic_term_id' => $term->id,
            'status' => $status,
            'total_units' => 0,
        ]);
    }
}
