<?php

namespace Tests\Feature\Console;

use App\Domain\Academic\GradeStatus;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ReclassifyStudentEnrollmentCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function makeTerm(string $semester = '2nd'): AcademicTerm
    {
        return AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => $semester, 'status' => AcademicTermStatus::SemesterOngoing,
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

    private function makeSubject(string $code): Subject
    {
        return Subject::create(['code' => $code, 'title' => $code.' Title', 'units' => 3.0, 'status' => SubjectStatus::Active]);
    }

    private function placeSubject(Curriculum $curriculum, Subject $subject, int $yearLevel, string $semester = '2nd'): CurriculumSubject
    {
        return CurriculumSubject::create([
            'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id,
            'year_level' => $yearLevel, 'semester' => $semester, 'is_required' => true,
        ]);
    }

    /**
     * The student has a result from an earlier term in a subject no curriculum places, so the system knows
     * them (owner, 2026-10-13: a student with no record at all is not classified) without that result
     * touching any rule under test.
     */
    private function makeStudent(Curriculum $curriculum, string $email, int $yearLevel = 2, ?string $category = null): StudentProfile
    {
        $user = User::create([
            'name' => 'Test Student', 'email' => $email,
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active,
        ]);

        $student = StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => 'STU-'.$user->id,
            'program_id' => $curriculum->program_id,
            'curriculum_id' => $curriculum->id,
            'year_level' => $yearLevel,
            'enrollment_category' => $category,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);

        $anchorTerm = AcademicTerm::firstOrCreate(
            ['school_year' => '2019-2020', 'semester' => '1st'],
            ['status' => AcademicTermStatus::Archived],
        );
        $anchorSubject = Subject::firstOrCreate(
            ['code' => 'RECORD-ANCHOR'],
            ['title' => 'Record anchor', 'units' => 3.0, 'status' => SubjectStatus::Active],
        );
        $encoder = User::firstOrCreate(
            ['email' => 'record-anchor.encoder@grc.test'],
            ['name' => 'Record encoder', 'password' => self::PASSWORD, 'role' => UserRole::RegistrarStaff, 'status' => UserStatus::Active],
        );
        AcademicGrade::create([
            'student_id' => $student->id, 'subject_id' => $anchorSubject->id, 'academic_term_id' => $anchorTerm->id,
            'mark' => '2.00', 'status' => GradeStatus::Locked, 'encoded_by' => $encoder->id,
        ]);

        return $student;
    }

    private function makeRegistrarHead(): User
    {
        return User::create([
            'name' => 'Registrar', 'email' => 'registrar.reclassify-cmd@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::RegistrarHead, 'status' => UserStatus::Active,
        ]);
    }

    private function makePlan(AcademicTerm $term, Curriculum $curriculum, int $yearLevel): AcademicTermSectionPlan
    {
        return AcademicTermSectionPlan::create([
            'academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id,
            'college' => 'ccs', 'year_level' => $yearLevel, 'section_count' => 1,
            'students_per_block' => 40, 'status' => 'submitted',
        ]);
    }

    private function makeBlockSection(AcademicTerm $term, AcademicTermSectionPlan $plan, Subject $subject): Section
    {
        return Section::create([
            'academic_term_id' => $term->id, 'section_plan_id' => $plan->id, 'subject_id' => $subject->id,
            'section_code' => 'IT201', 'schedule_days' => 'MWF', 'starts_at_time' => '08:00:00',
            'ends_at_time' => '09:00:00', 'capacity' => 40, 'is_block_exclusive' => true,
            'status' => SectionStatus::Published,
        ]);
    }

    private function makePlainSection(AcademicTerm $term, Subject $subject): Section
    {
        return Section::create([
            'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'section_code' => 'A',
            'capacity' => 40, 'is_block_exclusive' => false, 'status' => SectionStatus::Published,
        ]);
    }

    public function test_dry_run_does_not_report_an_undetermined_student_as_changed(): void
    {
        $this->makeRegistrarHead();
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        // No plan, no block section published for year level 2 this term
        // at all — the student's standing is undetermined.
        $student = $this->makeStudent($curriculum, 'undetermined-cmd@grc.test', yearLevel: 2, category: null);

        $exitCode = Artisan::call('students:reclassify', ['--dry-run' => true]);
        $output = Artisan::output();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('0 of 1 student(s) would change.', $output);
        self::assertStringNotContainsString(sprintf('Student #%d:', $student->id), $output);
        self::assertStringNotContainsString('unset -> regular', $output);

        // Nothing was written, since --dry-run.
        self::assertNull($student->fresh()->enrollment_category);
    }

    public function test_dry_run_still_reports_a_genuinely_determined_category_change(): void
    {
        $this->makeRegistrarHead();
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 2);
        $standard = $this->makeSubject('CS201');
        $this->placeSubject($curriculum, $standard, 2);
        $this->makeBlockSection($term, $plan, $standard);
        $backlog = $this->makeSubject('ITC');
        $this->placeSubject($curriculum, $backlog, 1, '1st');
        $this->makePlainSection($term, $backlog);
        $student = $this->makeStudent($curriculum, 'determined-cmd@grc.test', yearLevel: 2, category: 'regular');

        $exitCode = Artisan::call('students:reclassify', ['--dry-run' => true]);
        $output = Artisan::output();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('1 of 1 student(s) would change.', $output);
        self::assertStringContainsString(
            sprintf('Student #%d: regular -> irregular', $student->id),
            $output,
        );

        // Nothing was written, since --dry-run.
        self::assertSame('regular', $student->fresh()->enrollment_category);
    }

    public function test_a_real_run_does_not_report_or_count_an_undetermined_student_as_changed(): void
    {
        $this->makeRegistrarHead();
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        // No plan, no block section published for year level 2 this term.
        $student = $this->makeStudent($curriculum, 'undetermined-real-cmd@grc.test', yearLevel: 2, category: null);

        $exitCode = Artisan::call('students:reclassify');
        $output = Artisan::output();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('0 of 1 student(s) would change.', $output);
        self::assertStringNotContainsString(sprintf('Student #%d:', $student->id), $output);

        // Nothing was written for the undetermined student.
        self::assertNull($student->fresh()->enrollment_category);
    }
}
