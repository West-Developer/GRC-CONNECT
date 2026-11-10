<?php

namespace Tests\Unit\Actions\Academic;

use App\Actions\Academic\EvaluateCreditMappingStatus;
use App\Domain\Academic\TransfereeCreditStatus;
use App\Domain\Identity\StudentType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\Curriculum;
use App\Models\CurriculumMigration;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\TransfereeCredit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvaluateCreditMappingStatusTest extends TestCase
{
    use RefreshDatabase;

    private EvaluateCreditMappingStatus $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new EvaluateCreditMappingStatus;
    }

    private function createStudent(StudentType $type): StudentProfile
    {
        $program = Program::query()->first() ?? Program::create([
            'code' => 'BSIT-'.uniqid(),
            'name' => 'BS Information Technology',
            'status' => 'active',
        ]);

        $curriculum = Curriculum::query()->where('program_id', $program->id)->first() ?? Curriculum::create([
            'program_id' => $program->id,
            'name' => 'BSIT Curriculum 2025',
            'effective_school_year' => '2025-2026',
            'status' => 'active',
        ]);

        $user = User::create([
            'name' => 'Credit Test Student',
            'email' => 'credit.test.'.uniqid().'@grc.test',
            'password' => bcrypt('password'),
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
        ]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => 'STU-'.uniqid(),
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'student_type' => $type->value,
            'year_level' => 1,
            'admission_status' => 'admitted',
            'academic_standing' => 'good',
        ]);
    }

    private function getOrCreateSubject(): Subject
    {
        return Subject::query()->first() ?? Subject::create([
            'code' => 'SUBJ-'.uniqid(),
            'title' => 'Test Subject Title',
            'units' => 3,
            'status' => 'active',
        ]);
    }

    private function createCredit(StudentProfile $student, Subject $subject, TransfereeCreditStatus $status): TransfereeCredit
    {
        return TransfereeCredit::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'source_institution' => 'Previous University',
            'source_subject_code' => 'PREV-101',
            'source_subject_title' => 'Introduction to Computing',
            'source_grade' => '1.50',
            'credited_units' => 3.0,
            'status' => $status,
        ]);
    }

    public function test_transferee_with_only_saved_drafts_is_incomplete_and_told_to_submit(): void
    {
        $student = $this->createStudent(StudentType::Transferee);
        $this->createCredit($student, $this->getOrCreateSubject(), TransfereeCreditStatus::Draft);

        $result = $this->action->execute($student);

        $this->assertFalse($result->isCompleted);
        $this->assertTrue($result->requiresCreditMapping);
        $this->assertSame(0, $result->totalCredits);
        $this->assertStringContainsString('Submit your list', (string) $result->reason);
    }

    public function test_a_draft_does_not_count_as_a_credit_in_progress(): void
    {
        $student = $this->createStudent(StudentType::Transferee);
        $subject = $this->getOrCreateSubject();
        $this->createCredit($student, $subject, TransfereeCreditStatus::Pending);
        $this->createCredit($student, $subject, TransfereeCreditStatus::Draft);

        $result = $this->action->execute($student);

        $this->assertFalse($result->isCompleted);
        $this->assertSame(1, $result->totalCredits);
        $this->assertSame(1, $result->pendingCredits);
    }

    public function test_drafts_do_not_hold_back_a_transferee_whose_sent_credits_are_all_decided(): void
    {
        $student = $this->createStudent(StudentType::Transferee);
        $subject = $this->getOrCreateSubject();
        $this->createCredit($student, $subject, TransfereeCreditStatus::Approved);
        $this->createCredit($student, $subject, TransfereeCreditStatus::Draft);

        $result = $this->action->execute($student);

        $this->assertTrue($result->isCompleted);
        $this->assertNull($result->reason);
        $this->assertSame(1, $result->totalCredits);
    }

    public function test_returnee_with_only_saved_drafts_is_told_to_submit(): void
    {
        $student = $this->createStudent(StudentType::Returnee);
        $this->createCredit($student, $this->getOrCreateSubject(), TransfereeCreditStatus::Draft);

        $result = $this->action->execute($student);

        $this->assertFalse($result->isCompleted);
        $this->assertStringContainsString('Submit your list', (string) $result->reason);
    }

    public function test_the_reasons_name_the_registrar_head_not_just_the_registrar(): void
    {
        $transferee = $this->createStudent(StudentType::Transferee);
        $returnee = $this->createStudent(StudentType::Returnee);

        $this->assertStringContainsString('Registrar Head', (string) $this->action->execute($transferee)->reason);
        $this->assertStringContainsString('Registrar Head', (string) $this->action->execute($returnee)->reason);
    }

    public function test_freshman_requires_no_credit_mapping(): void
    {
        $student = $this->createStudent(StudentType::Freshman);
        $result = $this->action->execute($student);

        $this->assertTrue($result->isCompleted);
        $this->assertFalse($result->requiresCreditMapping);
        $this->assertNull($result->reason);
    }

    public function test_transferee_with_no_credits_is_incomplete(): void
    {
        $student = $this->createStudent(StudentType::Transferee);
        $result = $this->action->execute($student);

        $this->assertFalse($result->isCompleted);
        $this->assertTrue($result->requiresCreditMapping);
        $this->assertNotNull($result->reason);
    }

    public function test_transferee_with_pending_credits_is_in_progress(): void
    {
        $student = $this->createStudent(StudentType::Transferee);
        $subject = $this->getOrCreateSubject();
        $this->createCredit($student, $subject, TransfereeCreditStatus::Pending);

        $result = $this->action->execute($student);

        $this->assertFalse($result->isCompleted);
        $this->assertTrue($result->requiresCreditMapping);
        $this->assertEquals(1, $result->pendingCredits);
    }

    public function test_transferee_with_completed_credits_is_completed(): void
    {
        $student = $this->createStudent(StudentType::Transferee);
        $subject = $this->getOrCreateSubject();
        $this->createCredit($student, $subject, TransfereeCreditStatus::Approved);

        $result = $this->action->execute($student);

        $this->assertTrue($result->isCompleted);
        $this->assertTrue($result->requiresCreditMapping);
        $this->assertNull($result->reason);
        $this->assertEquals(1, $result->approvedCredits);
    }

    public function test_returnee_with_curriculum_migration_is_completed(): void
    {
        $student = $this->createStudent(StudentType::Returnee);
        $targetCurriculum = Curriculum::query()->where('id', '!=', $student->curriculum_id)->first() ?? Curriculum::create([
            'program_id' => $student->program_id,
            'name' => 'Target Curriculum '.uniqid(),
            'effective_school_year' => '2026-2027',
            'status' => 'active',
        ]);

        CurriculumMigration::create([
            'student_id' => $student->id,
            'source_curriculum_id' => $student->curriculum_id,
            'target_curriculum_id' => $targetCurriculum->id,
            'processed_by' => $student->user_id,
            'migrated_at' => now(),
        ]);

        $result = $this->action->execute($student);

        $this->assertTrue($result->isCompleted);
        $this->assertTrue($result->requiresCreditMapping);
        $this->assertNull($result->reason);
    }

    public function test_returnee_with_no_migration_or_credits_is_incomplete(): void
    {
        $student = $this->createStudent(StudentType::Returnee);
        $result = $this->action->execute($student);

        $this->assertFalse($result->isCompleted);
        $this->assertTrue($result->requiresCreditMapping);
        $this->assertNotNull($result->reason);
    }

    public function test_returnee_with_pending_credits_is_in_progress(): void
    {
        $student = $this->createStudent(StudentType::Returnee);
        $subject = $this->getOrCreateSubject();
        $this->createCredit($student, $subject, TransfereeCreditStatus::Pending);

        $result = $this->action->execute($student);

        $this->assertFalse($result->isCompleted);
        $this->assertTrue($result->requiresCreditMapping);
        $this->assertEquals(1, $result->pendingCredits);
    }

    /**
     * The owner's rule (2026-10-15): once the Program Chair has passed the credits to the Registrar Head,
     * the student may go on to enrol; the Head's approval no longer holds enrolment back.
     */
    public function test_transferee_whose_credits_the_chair_passed_to_the_registrar_head_may_enrol(): void
    {
        $student = $this->createStudent(StudentType::Transferee);
        $subject = $this->getOrCreateSubject();
        $this->createCredit($student, $subject, TransfereeCreditStatus::Endorsed);

        $result = $this->action->execute($student);

        $this->assertTrue($result->isCompleted);
        $this->assertTrue($result->requiresCreditMapping);
        $this->assertNull($result->reason);
        $this->assertSame(1, $result->endorsedCredits);
        $this->assertSame(0, $result->pendingCredits);
    }

    public function test_returnee_whose_credits_the_chair_passed_to_the_registrar_head_may_enrol(): void
    {
        $student = $this->createStudent(StudentType::Returnee);
        $subject = $this->getOrCreateSubject();
        $this->createCredit($student, $subject, TransfereeCreditStatus::Endorsed);

        $result = $this->action->execute($student);

        $this->assertTrue($result->isCompleted);
        $this->assertNull($result->reason);
        $this->assertSame(1, $result->endorsedCredits);
    }

    public function test_a_credit_still_with_the_program_chair_holds_enrolment_back_even_when_another_was_passed_on(): void
    {
        $student = $this->createStudent(StudentType::Transferee);
        $subject = $this->getOrCreateSubject();
        $this->createCredit($student, $subject, TransfereeCreditStatus::Endorsed);
        $this->createCredit($student, $subject, TransfereeCreditStatus::Pending);

        $result = $this->action->execute($student);

        $this->assertFalse($result->isCompleted);
        $this->assertStringContainsString('Program Head', (string) $result->reason);
        $this->assertSame(1, $result->pendingCredits);
        $this->assertSame(1, $result->endorsedCredits);
    }
}
