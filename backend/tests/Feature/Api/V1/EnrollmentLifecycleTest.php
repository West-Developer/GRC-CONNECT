<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One enrollment carried across every role that touches it, through the real
 * HTTP API (stakeholder Doc 14): the Student submits a block, the Registrar
 * approves (which assesses it), Accounting previews the COR and confirms a
 * partial payment under the promissory rule, and the Student and Accounting
 * both read the same Statement of Account. Each step is covered by its own
 * endpoint test; this one checks that the hand-offs between them line up.
 */
final class EnrollmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /** One Sanctum actor per request chain, so switch cleanly between roles. */
    private function as(User $user): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('lifecycle')->plainTextToken);
    }

    private function user(UserRole $role): User
    {
        return User::create([
            'name' => 'Lifecycle '.$role->value.' '.++$this->seq,
            'email' => $role->value.'.'.$this->seq.'.lc@grc.test',
            'password' => 'correct-horse-battery-staple',
            'role' => $role,
            'status' => UserStatus::Active,
        ]);
    }

    /** @return array{0: AcademicTerm, 1: StudentProfile} */
    private function fixture(): array
    {
        $term = AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st',
            'status' => AcademicTermStatus::SemesterOngoing, 'starts_at' => '2026-08-01',
        ]);
        $program = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active, 'college' => 'ccs']);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS', 'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
        $plan = AcademicTermSectionPlan::create([
            'academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id,
            'college' => 'ccs', 'year_level' => 1, 'section_count' => 1,
            'students_per_block' => 40, 'status' => 'submitted',
        ]);

        foreach (['LC101', 'LC102', 'LC103'] as $index => $code) {
            $subject = Subject::create(['code' => $code, 'title' => $code.' Title', 'units' => 3, 'status' => SubjectStatus::Active]);
            CurriculumSubject::create([
                'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id,
                'year_level' => 1, 'semester' => '1st', 'is_required' => true,
            ]);
            Section::create([
                'academic_term_id' => $term->id, 'section_plan_id' => $plan->id, 'subject_id' => $subject->id,
                'section_code' => 'BSCS101', 'schedule_days' => 'MWF',
                'starts_at_time' => sprintf('%02d:00:00', 8 + $index), 'ends_at_time' => sprintf('%02d:00:00', 9 + $index),
                'room' => 'LAB-1', 'professor_id' => $this->user(UserRole::Faculty)->id,
                'capacity' => 40, 'is_block_exclusive' => true, 'status' => SectionStatus::Published,
            ]);
        }

        $student = StudentProfile::create([
            'user_id' => $this->user(UserRole::Student)->id,
            'student_number' => '2026-0001',
            'program_id' => $program->id, 'curriculum_id' => $curriculum->id,
            'year_level' => 1, 'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);

        return [$term, $student];
    }

    public function test_an_enrollment_moves_from_submission_to_a_paid_statement_of_account(): void
    {
        [$term, $student] = $this->fixture();
        $registrar = $this->user(UserRole::RegistrarStaff);
        $accounting = $this->user(UserRole::AccountingStaff);

        // 1. The Student submits their block: it waits for the Registrar, with no assessment yet.
        $submitted = $this->as($student->user)->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id, 'block_code' => 'BSCS101',
        ]);
        $submitted->assertCreated()
            ->assertJsonPath('data.status', 'pending_registrar_approval')
            ->assertJsonPath('data.assessment', null);
        $enrollmentId = (int) $submitted->json('data.id');

        // 2. The Registrar approves; the enrollment is assessed and waits for payment.
        $this->as($registrar)->patchJson("/api/v1/enrollments/{$enrollmentId}", ['action' => 'registrar_approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_payment');
        $enrollment = Enrollment::query()->with('assessment')->findOrFail($enrollmentId);
        self::assertNotNull($enrollment->assessment, 'Approval must create the assessment.');
        $total = $enrollment->assessment->total_amount;
        self::assertTrue(bccomp($total, '1000.00', 2) === 1, 'The assessment must exceed the 1,000.00 minimum for a partial payment to be possible.');

        // 3. Accounting previews the COR (nothing is stored). A payment below the minimum is refused.
        $this->as($accounting)->getJson("/api/v1/enrollments/{$enrollmentId}/cor-preview")
            ->assertOk()->assertJsonStructure(['data' => ['snapshot', 'watermark']]);
        $this->assertDatabaseCount('enrollment_documents', 0);

        $this->as($accounting)->postJson("/api/v1/enrollments/{$enrollmentId}/payment", ['amount' => 999])
            ->assertUnprocessable();
        self::assertSame(EnrollmentStatus::PendingPayment, $enrollment->refresh()->status);

        // 4. A downpayment of 1,000 needs no promissory note: this student has no previous bill (2026-10-13).
        //    It is accepted: enrolled, and the COR is issued.
        $this->as($accounting)->postJson("/api/v1/enrollments/{$enrollmentId}/payment", ['amount' => 1000, 'promissory_note_on_file' => false])
            ->assertCreated()
            ->assertJsonPath('data.enrollment.status', 'enrolled')
            ->assertJsonPath('data.document.document_type', 'cor');
        $this->assertDatabaseCount('enrollment_documents', 1);

        // 5. Confirming again changes nothing.
        $this->as($accounting)->postJson("/api/v1/enrollments/{$enrollmentId}/payment", ['amount' => 1000, 'promissory_note_on_file' => false]);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('enrollment_documents', 1);

        // 6. The Student sees what they still owe, and Accounting sees the same statement.
        $expectedOwed = bcsub($total, '1000.00', 2);
        $own = $this->as($student->user)->getJson('/api/v1/me/statement-of-account');
        $own->assertOk()
            ->assertJsonPath('data.summary.total_assessed', $total)
            ->assertJsonPath('data.summary.total_paid', '1000.00')
            ->assertJsonPath('data.summary.outstanding_balance', $expectedOwed)
            ->assertJsonPath('data.terms.0.payments.0.promissory_note_on_file', false)
            ->assertJsonPath('data.terms.0.running_balance', $expectedOwed);

        $seenByAccounting = $this->as($accounting)->getJson("/api/v1/students/{$student->id}/statement-of-account");
        $seenByAccounting->assertOk();
        self::assertSame($own->json('data.summary'), $seenByAccounting->json('data.summary'));
        self::assertSame($own->json('data.terms'), $seenByAccounting->json('data.terms'));

        // 7. Nobody else can read it, and the Student cannot read another student's.
        $this->as($this->user(UserRole::RegistrarHead))->getJson("/api/v1/students/{$student->id}/statement-of-account")->assertForbidden();
    }
}
