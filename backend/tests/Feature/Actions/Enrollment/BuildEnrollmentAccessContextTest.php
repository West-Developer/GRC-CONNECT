<?php

namespace Tests\Feature\Actions\Enrollment;

use App\Actions\Enrollment\BuildEnrollmentAccessContext;
use App\Domain\Academic\GradeStatus;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Enrollment\EnrollmentAudience;
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
use Tests\TestCase;

final class BuildEnrollmentAccessContextTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function makeCurriculum(): Curriculum
    {
        $program = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);

        return Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
    }

    /**
     * `$withRecord` (the default) gives the student a result from an earlier term in a subject no curriculum
     * places, so the system knows them (owner, 2026-10-13: a student with no record at all is not classified)
     * without that result touching any rule under test. Pass false for a brand-new student.
     */
    private function makeStudent(Curriculum $curriculum, string $email, ?string $category, bool $withRecord = true): StudentProfile
    {
        $user = User::create([
            'name' => 'Test Student', 'email' => $email,
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active,
        ]);

        $student = StudentProfile::create([
            'user_id' => $user->id, 'student_number' => 'STU-'.$user->id,
            'program_id' => $curriculum->program_id, 'curriculum_id' => $curriculum->id,
            'year_level' => 2, 'enrollment_category' => $category,
            'admission_status' => AdmissionStatus::Admitted, 'academic_standing' => AcademicStanding::Good,
        ]);

        if ($withRecord) {
            $anchorTerm = AcademicTerm::firstOrCreate(
                ['school_year' => '2019-2020', 'semester' => '1st'],
                ['status' => AcademicTermStatus::Archived],
            );
            $anchorSubject = Subject::firstOrCreate(
                ['code' => 'RECORD-ANCHOR'],
                ['title' => 'Record anchor', 'units' => 3.0, 'status' => SubjectStatus::Active],
            );
            // Not a RegistrarHead: one test below depends on there being none.
            $encoder = User::firstOrCreate(
                ['email' => 'record-anchor.encoder@grc.test'],
                ['name' => 'Record encoder', 'password' => self::PASSWORD, 'role' => UserRole::RegistrarStaff, 'status' => UserStatus::Active],
            );
            AcademicGrade::create([
                'student_id' => $student->id, 'subject_id' => $anchorSubject->id, 'academic_term_id' => $anchorTerm->id,
                'mark' => '2.00', 'status' => GradeStatus::Locked, 'encoded_by' => $encoder->id,
            ]);
        }

        return $student;
    }

    private function makeBlock(AcademicTerm $term, Curriculum $curriculum): void
    {
        $plan = AcademicTermSectionPlan::create([
            'academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id,
            'college' => 'ccs', 'year_level' => 2, 'section_count' => 1,
            'students_per_block' => 40, 'status' => 'submitted',
        ]);
        $subject = Subject::create(['code' => 'CS201', 'title' => 'CS201 Title', 'units' => 3, 'status' => SubjectStatus::Active]);
        CurriculumSubject::create([
            'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id,
            'year_level' => 2, 'semester' => '2nd', 'is_required' => true,
        ]);
        Section::create([
            'academic_term_id' => $term->id, 'section_plan_id' => $plan->id, 'subject_id' => $subject->id,
            'section_code' => 'IT201', 'schedule_days' => 'MWF', 'starts_at_time' => '08:00:00',
            'ends_at_time' => '09:00:00', 'capacity' => 40, 'is_block_exclusive' => true,
            'status' => SectionStatus::Published,
        ]);
    }

    public function test_a_stale_stored_category_self_heals_to_the_live_verdict_on_the_ongoing_term(): void
    {
        $term = AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '2nd', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        $curriculum = $this->makeCurriculum();
        $this->makeBlock($term, $curriculum);
        // Stored as irregular from a stale prior computation; she fits the
        // block exactly now (no backlog, nothing failed early).
        $student = $this->makeStudent($curriculum, 'stale@grc.test', 'irregular');
        User::create([
            'name' => 'Registrar', 'email' => 'registrar.selfheal@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::RegistrarHead, 'status' => UserStatus::Active,
        ]);

        $context = app(BuildEnrollmentAccessContext::class)->execute($term, $student);

        self::assertNotSame(EnrollmentAudience::Irregular, $context->viewerAudience);
        self::assertSame('regular', $student->fresh()->enrollment_category);
    }

    public function test_no_registrar_head_user_skips_the_persist_without_throwing(): void
    {
        $term = AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '2nd', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        $curriculum = $this->makeCurriculum();
        $this->makeBlock($term, $curriculum);
        // Stored as irregular from a stale prior computation; she fits the
        // block exactly now, so the live verdict is Regular and a persist
        // will be attempted — but deliberately no RegistrarHead user exists
        // to attribute the audit to, so the persist must silently no-op
        // rather than throw. This is the highest-stakes guarantee here:
        // an ordinary student loading their own enrollment page must never
        // hit an exception because no registrar exists yet.
        $student = $this->makeStudent($curriculum, 'no-registrar@grc.test', 'irregular');

        $context = app(BuildEnrollmentAccessContext::class)->execute($term, $student);

        self::assertNotSame(EnrollmentAudience::Irregular, $context->viewerAudience);
        self::assertSame('irregular', $student->fresh()->enrollment_category);
    }

    public function test_a_new_student_with_no_record_is_never_flipped_to_irregular_by_opening_enrollment(): void
    {
        // The 2026-10-13 bug: a year-level-2+ student Admission had just created, with no grades and no
        // earlier enrollment, turned Irregular within seconds of opening the Enrollment page, because every
        // earlier subject looked "not passed". Their stored category (what Admission chose) must stand.
        $term = AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '2nd', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        $curriculum = $this->makeCurriculum();
        $this->makeBlock($term, $curriculum);
        // An earlier-year subject with an open (non-block) section, so the old rule saw a backlog.
        $earlier = Subject::create(['code' => 'CS101', 'title' => 'CS101 Title', 'units' => 3, 'status' => SubjectStatus::Active]);
        CurriculumSubject::create([
            'curriculum_id' => $curriculum->id, 'subject_id' => $earlier->id,
            'year_level' => 1, 'semester' => '1st', 'is_required' => true,
        ]);
        Section::create([
            'academic_term_id' => $term->id, 'subject_id' => $earlier->id, 'section_code' => 'A',
            'capacity' => 40, 'is_block_exclusive' => false, 'status' => SectionStatus::Published,
        ]);
        $student = $this->makeStudent($curriculum, 'brand-new@grc.test', 'regular', withRecord: false);
        User::create([
            'name' => 'Registrar', 'email' => 'registrar.brandnew@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::RegistrarHead, 'status' => UserStatus::Active,
        ]);

        $context = app(BuildEnrollmentAccessContext::class)->execute($term, $student);

        self::assertNotSame(EnrollmentAudience::Irregular, $context->viewerAudience);
        $fresh = $student->fresh();
        self::assertSame('regular', $fresh->enrollment_category);
        self::assertNull($fresh->enrollment_category_derived_at);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_browsing_an_archived_term_never_mutates_stored_standing(): void
    {
        $term = AcademicTerm::create([
            'school_year' => '2025-2026', 'semester' => '2nd', 'status' => AcademicTermStatus::Archived,
        ]);
        $curriculum = $this->makeCurriculum();
        $this->makeBlock($term, $curriculum);
        $student = $this->makeStudent($curriculum, 'archived@grc.test', 'irregular');

        $context = app(BuildEnrollmentAccessContext::class)->execute($term, $student);

        self::assertSame(EnrollmentAudience::Irregular, $context->viewerAudience);
        self::assertSame('irregular', $student->fresh()->enrollment_category);
    }

    public function test_no_block_published_yet_falls_back_to_the_stored_category_without_writing(): void
    {
        $term = AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '2nd', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        $curriculum = $this->makeCurriculum();
        // No block published for year level 2 this term.
        $student = $this->makeStudent($curriculum, 'undetermined@grc.test', 'regular');

        $context = app(BuildEnrollmentAccessContext::class)->execute($term, $student);

        self::assertNotSame(EnrollmentAudience::Irregular, $context->viewerAudience);
        self::assertSame('regular', $student->fresh()->enrollment_category);
    }

    /**
     * `ReclassifyStudentEnrollmentCategory` and its own collaborators
     * (`AuditRecorder`, `ClassifyEnrollmentStanding`) are all `final
     * readonly` classes, so Mockery cannot generate a subclass double for
     * any of them (verified directly: `Mockery::mock()` on a final class
     * throws its own "is marked final" exception before this test even
     * runs). Rather than mock, this forces the REAL `execute()` call to
     * fail for a genuine reason: it corrupts the in-memory (never
     * persisted) `user_id` on the exact `StudentProfile` instance passed
     * through to `persistBestEffort()`, so `Notification::create()` — the
     * last write inside `executeMany()`'s DB transaction — hits a real
     * foreign key violation. That's a faithful stand-in for "any other
     * failure" (a DB error, etc.) the class's docblock promises to
     * swallow, without touching any other row or requiring a mock.
     */
    public function test_a_persist_failure_during_self_heal_is_swallowed_and_routing_still_uses_the_live_verdict(): void
    {
        $term = AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '2nd', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        $curriculum = $this->makeCurriculum();
        $this->makeBlock($term, $curriculum);
        // Stored as irregular from a stale prior computation; she fits the
        // block exactly now, so the live verdict is Regular and a persist
        // will be attempted — and fail, per the corrupted user_id below.
        $student = $this->makeStudent($curriculum, 'persist-failure@grc.test', 'irregular');
        User::create([
            'name' => 'Registrar', 'email' => 'registrar.persistfailure@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::RegistrarHead, 'status' => UserStatus::Active,
        ]);

        // Never saved — only this in-memory attribute is wrong, so the
        // student_profiles row on disk is untouched. persistBestEffort()
        // is handed this exact object, so the Notification insert deep
        // inside its write transaction fails on a real FK constraint.
        $student->user_id = 999999999;

        $context = app(BuildEnrollmentAccessContext::class)->execute($term, $student);

        // Routing used the live-computed verdict and is correct regardless
        // of the swallowed persist failure.
        self::assertNotSame(EnrollmentAudience::Irregular, $context->viewerAudience);

        // The forced failure happened inside executeMany()'s own
        // DB::transaction(), so nothing it attempted to write survives —
        // the stored category is untouched.
        self::assertSame('irregular', StudentProfile::query()->find($student->id)->enrollment_category);
    }
}
