<?php

namespace Tests\Feature\Database;

use App\Domain\Academic\TransfereeCreditStatus;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\WithdrawalStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\TransfereeCredit;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class TransfereeAndWithdrawalMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_transferee_credits_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('transferee_credits'));
        $this->assertTrue(Schema::hasColumns('transferee_credits', [
            'id', 'student_id', 'source_institution', 'source_subject_code',
            'source_subject_title', 'source_grade', 'credited_units', 'subject_id',
            'status', 'processed_by', 'processed_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_deleting_the_student_profile_cascades_to_their_transferee_credits(): void
    {
        $student = $this->makeStudent();

        $credit = TransfereeCredit::create([
            'student_id' => $student->id,
            'source_institution' => 'Synthetic Partner College',
            'source_subject_code' => 'ITE-101',
            'source_subject_title' => 'Introduction to Information Technology',
            'credited_units' => 3,
            'status' => TransfereeCreditStatus::Approved,
        ]);

        $student->delete();

        $this->assertDatabaseMissing('transferee_credits', ['id' => $credit->id]);
    }

    public function test_deleting_the_mapped_subject_nulls_the_reference_rather_than_the_credit(): void
    {
        $subject = Subject::create([
            'code' => 'CS101', 'title' => 'Introduction to Computing', 'units' => 3,
            'status' => SubjectStatus::Active,
        ]);

        $credit = TransfereeCredit::create([
            'student_id' => $this->makeStudent()->id,
            'source_institution' => 'Synthetic Partner College',
            'source_subject_code' => 'ITE-101',
            'source_subject_title' => 'Introduction to Information Technology',
            'credited_units' => 3,
            'subject_id' => $subject->id,
            'status' => TransfereeCreditStatus::Approved,
        ]);

        $subject->delete();

        $this->assertNull($credit->refresh()->subject_id);
    }

    public function test_withdrawal_requests_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('withdrawal_requests'));
        $this->assertTrue(Schema::hasColumns('withdrawal_requests', [
            'id', 'enrollment_id', 'reason', 'status', 'processed_by',
            'processed_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_deleting_the_enrollment_cascades_to_its_withdrawal_request(): void
    {
        $enrollment = $this->makeEnrollment();

        $request = WithdrawalRequest::create([
            'enrollment_id' => $enrollment->id,
            'reason' => 'Relocated to another institution.',
            'status' => WithdrawalStatus::Pending,
        ]);

        $enrollment->delete();

        $this->assertDatabaseMissing('withdrawal_requests', ['id' => $request->id]);
    }

    public function test_deleting_the_processor_nulls_the_reference_rather_than_the_request(): void
    {
        $registrar = User::create([
            'name' => 'Test registrar_head',
            'email' => 'registrar.'.uniqid().'@grc.test',
            'password' => 'irrelevant-password',
            'role' => UserRole::RegistrarHead,
            'status' => UserStatus::Active,
        ]);

        $request = WithdrawalRequest::create([
            'enrollment_id' => $this->makeEnrollment()->id,
            'reason' => 'Relocated to another institution.',
            'status' => WithdrawalStatus::Approved,
            'processed_by' => $registrar->id,
            'processed_at' => now(),
        ]);

        $registrar->delete();

        $this->assertNull($request->refresh()->processed_by);
        $this->assertDatabaseHas('withdrawal_requests', ['id' => $request->id]);
    }

    private function makeStudent(): StudentProfile
    {
        $program = Program::create([
            'code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active,
        ]);

        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);

        $user = User::create([
            'name' => 'Test Student',
            'email' => 'student.'.uniqid().'@grc.test',
            'password' => 'irrelevant-password',
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
        ]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => 'STU-'.uniqid(),
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
    }

    private function makeEnrollment(): Enrollment
    {
        return Enrollment::create([
            'student_id' => $this->makeStudent()->id,
            'academic_term_id' => AcademicTerm::create([
                'school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
            ])->id,
            'status' => EnrollmentStatus::Enrolled,
            'total_units' => 0,
        ]);
    }
}
