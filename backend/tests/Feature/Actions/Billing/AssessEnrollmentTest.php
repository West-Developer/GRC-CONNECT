<?php

namespace Tests\Feature\Actions\Billing;

use App\Actions\Billing\AssessEnrollment;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\FeeSchedule;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A miscellaneous fee can be scoped to one semester (stakeholder Doc 15): a `null` semester applies
 * to every term, matching every fee's behaviour before this scoping existed.
 */
final class AssessEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function enrollment(string $semester): Enrollment
    {
        $suffix = ++self::$seq;
        $term = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => $semester, 'status' => AcademicTermStatus::SemesterOngoing]);
        $program = Program::create(['code' => 'BSIT'.$suffix, 'name' => 'BS IT', 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::create(['program_id' => $program->id, 'name' => 'BSIT Curriculum', 'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active]);
        $user = User::create(['name' => 'Test Student', 'email' => 'student.fee.'.$suffix.'@grc.test', 'password' => 'correct-horse-battery-staple', 'role' => UserRole::Student, 'status' => UserStatus::Active]);
        $student = StudentProfile::create([
            'user_id' => $user->id, 'student_number' => 'STU-'.$user->id, 'program_id' => $program->id,
            'curriculum_id' => $curriculum->id, 'year_level' => 1, 'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);

        return Enrollment::create([
            'student_id' => $student->id, 'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::PendingRegistrarApproval, 'total_units' => 3, 'submitted_at' => now(),
        ]);
    }

    public function test_a_fee_with_no_semester_is_charged_in_every_semester(): void
    {
        FeeSchedule::create(['category' => 'tuition', 'label' => 'Tuition', 'amount' => '200.00', 'is_active' => true]);
        FeeSchedule::create(['category' => 'miscellaneous', 'semester' => null, 'label' => 'Registration', 'amount' => '200.00', 'is_active' => true]);

        foreach (['1st', '2nd'] as $semester) {
            $assessment = app(AssessEnrollment::class)->execute($this->enrollment($semester));
            self::assertTrue($assessment->items->contains('label', 'Registration'));
        }
    }

    public function test_a_fee_scoped_to_one_semester_is_charged_only_then(): void
    {
        FeeSchedule::create(['category' => 'tuition', 'label' => 'Tuition', 'amount' => '200.00', 'is_active' => true]);
        FeeSchedule::create(['category' => 'miscellaneous', 'semester' => '2nd', 'label' => 'Graduation Fee', 'amount' => '500.00', 'is_active' => true]);

        $firstSemAssessment = app(AssessEnrollment::class)->execute($this->enrollment('1st'));
        self::assertFalse($firstSemAssessment->items->contains('label', 'Graduation Fee'));

        $secondSemAssessment = app(AssessEnrollment::class)->execute($this->enrollment('2nd'));
        self::assertTrue($secondSemAssessment->items->contains('label', 'Graduation Fee'));
    }
}
