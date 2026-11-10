<?php

namespace Tests\Feature\Database;

use App\Domain\Academic\GradeStatus;
use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Enrollment\EnrollmentCategory;
use App\Domain\Enrollment\EnrollmentStatus;
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
use App\Models\AuditLog;
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
 * Migration `2026_09_28_000001_resync_stale_pre_fix_enrollment_classifications`: a one-time,
 * evidence-scoped correction for real students left `Irregular` by a classifier rule
 * (`needs_removing_completed`) that was removed before this fix — `ClassifyEnrollmentStanding` now
 * explicitly never treats a passed subject as a reason to go Irregular.
 */
final class ResyncStalePreFixEnrollmentClassificationsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function runMigration(): void
    {
        (require database_path('migrations/2026_09_28_000001_resync_stale_pre_fix_enrollment_classifications.php'))->up();
    }

    private function makeItAdmin(): User
    {
        return User::create([
            'name' => 'IT Admin', 'email' => 'it.admin.resync@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::ItAdmin, 'status' => UserStatus::Active,
        ]);
    }

    private function makeOngoingTerm(): AcademicTerm
    {
        return AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
    }

    private function makeCurriculum(): Curriculum
    {
        $program = Program::create(['code' => 'BSIT', 'name' => 'BS Information Technology', 'status' => ProgramStatus::Active]);

        return Curriculum::create(['program_id' => $program->id, 'name' => 'BSIT Curriculum', 'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active]);
    }

    private function makeStudent(Curriculum $curriculum, string $email, int $yearLevel = 1): StudentProfile
    {
        $user = User::create(['name' => 'Test Student', 'email' => $email, 'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => 'STU-'.$user->id,
            'program_id' => $curriculum->program_id,
            'curriculum_id' => $curriculum->id,
            'year_level' => $yearLevel,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
            'enrollment_category' => EnrollmentCategory::Irregular->value,
            'enrollment_category_derived_at' => now()->subDays(5),
        ]);
    }

    public function test_it_clears_a_stale_irregular_student_with_no_history_when_no_block_is_published(): void
    {
        $this->makeItAdmin();
        $term = $this->makeOngoingTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'stale.undetermined@grc.test');
        // No section/plan published for this curriculum+year this term: the fresh verdict is
        // undetermined, and this student has zero enrollments and zero grades to justify Irregular.

        $this->runMigration();

        $student->refresh();
        self::assertNull($student->enrollment_category);
        self::assertNull($student->enrollment_category_derived_at);
        $audit = AuditLog::query()->where('action', AuditAction::STUDENT_ENROLLMENT_CATEGORY_RECLASSIFIED)
            ->where('auditable_id', $student->id)->sole();
        self::assertSame(['enrollment_category' => 'irregular'], $audit->before_values);
        self::assertNull($audit->after_values['enrollment_category']);
    }

    public function test_it_still_clears_the_stale_value_when_a_block_is_published_because_a_student_with_no_record_is_undetermined(): void
    {
        // This migration only ever looks at students with zero enrollments and zero grades. Since
        // ADR 0047 (2026-10-13) such a student is undetermined for the classifier even when a block is
        // published, so the stale Irregular is cleared to NULL rather than set to a determinate Regular
        // (the migration ran once, on 2026-09-28; this only keeps its `up()` honest against the new rule).
        $this->makeItAdmin();
        $term = $this->makeOngoingTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'stale.determinate@grc.test');
        $subject = Subject::create(['code' => 'ITC', 'title' => 'Intro to Computing', 'units' => 3.0, 'status' => SubjectStatus::Active]);
        CurriculumSubject::create(['curriculum_id' => $curriculum->id, 'subject_id' => $subject->id, 'year_level' => 1, 'semester' => '1st', 'is_required' => true]);
        $plan = AcademicTermSectionPlan::create(['academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id, 'college' => 'ccs', 'year_level' => 1, 'section_count' => 1, 'students_per_block' => 40, 'status' => 'submitted']);
        Section::create(['academic_term_id' => $term->id, 'section_plan_id' => $plan->id, 'subject_id' => $subject->id, 'section_code' => 'IT101', 'capacity' => 40, 'is_block_exclusive' => true, 'status' => SectionStatus::Published]);

        $this->runMigration();

        $student->refresh();
        self::assertNull($student->enrollment_category);
        self::assertNull($student->enrollment_category_derived_at);
    }

    public function test_it_leaves_an_irregular_student_with_a_real_enrollment_untouched(): void
    {
        $this->makeItAdmin();
        $term = $this->makeOngoingTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'has.enrollment@grc.test');
        Enrollment::create([
            'student_id' => $student->id, 'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::PendingRegistrarApproval, 'total_units' => 3, 'submitted_at' => now(),
        ]);

        $this->runMigration();

        self::assertSame(EnrollmentCategory::Irregular->value, $student->refresh()->enrollment_category);
        self::assertSame(0, AuditLog::query()->where('auditable_id', $student->id)
            ->where('action', AuditAction::STUDENT_ENROLLMENT_CATEGORY_RECLASSIFIED)->count());
    }

    public function test_it_leaves_an_irregular_student_with_a_real_grade_untouched(): void
    {
        $admin = $this->makeItAdmin();
        $term = $this->makeOngoingTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'has.grade@grc.test');
        $subject = Subject::create(['code' => 'PROG1', 'title' => 'Programming 1', 'units' => 3.0, 'status' => SubjectStatus::Active]);
        AcademicGrade::create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'academic_term_id' => $term->id,
            'mark' => '1.50', 'status' => GradeStatus::Locked, 'encoded_by' => $admin->id, 'submitted_at' => now(), 'locked_at' => now(),
        ]);

        $this->runMigration();

        self::assertSame(EnrollmentCategory::Irregular->value, $student->refresh()->enrollment_category);
        self::assertSame(0, AuditLog::query()->where('auditable_id', $student->id)
            ->where('action', AuditAction::STUDENT_ENROLLMENT_CATEGORY_RECLASSIFIED)->count());
    }

    public function test_it_does_nothing_without_an_ongoing_term_or_a_suitable_actor(): void
    {
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'no.actor.no.term@grc.test');
        // No IT Admin/Registrar Head created, and no ongoing term either.

        $this->runMigration();

        self::assertSame(EnrollmentCategory::Irregular->value, $student->refresh()->enrollment_category);
    }

    public function test_a_regular_student_with_no_history_is_left_alone(): void
    {
        $this->makeItAdmin();
        $this->makeOngoingTerm();
        $curriculum = $this->makeCurriculum();
        $user = User::create(['name' => 'Regular Student', 'email' => 'already.regular@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active]);
        $student = StudentProfile::create([
            'user_id' => $user->id, 'student_number' => 'STU-'.$user->id, 'program_id' => $curriculum->program_id,
            'curriculum_id' => $curriculum->id, 'year_level' => 1, 'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good, 'enrollment_category' => EnrollmentCategory::Regular->value,
        ]);

        $this->runMigration();

        self::assertSame(EnrollmentCategory::Regular->value, $student->refresh()->enrollment_category);
        self::assertSame(0, AuditLog::query()->count());
    }
}
