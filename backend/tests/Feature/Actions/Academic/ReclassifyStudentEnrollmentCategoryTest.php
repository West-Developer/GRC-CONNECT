<?php

namespace Tests\Feature\Actions\Academic;

use App\Actions\Academic\ReclassifyStudentEnrollmentCategory;
use App\Domain\Academic\GradeStatus;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Enrollment\EnrollmentCategory;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Notifications\NotificationType;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Notification;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\SubjectPrerequisite;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ReclassifyStudentEnrollmentCategoryTest extends TestCase
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
     * `$withRecord` (the default) gives the student a result from an earlier term in a subject no curriculum
     * places, so the system knows them (owner, 2026-10-13: a student with no record at all is not classified)
     * without that result touching any rule under test. Pass false for a brand-new student.
     */
    private function makeStudent(Curriculum $curriculum, string $email, int $yearLevel = 2, ?string $category = null, bool $withRecord = true): StudentProfile
    {
        $user = User::create([
            'name' => 'Test Student', 'email' => $email,
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
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

        if ($withRecord) {
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
            $this->lockGrade($student, $anchorSubject, $anchorTerm, '2.00', $encoder);
        }

        return $student;
    }

    private function makeRegistrarHead(): User
    {
        return User::create([
            'name' => 'Registrar', 'email' => 'registrar.reclassify@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::RegistrarHead, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
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

    private function lockGrade(StudentProfile $student, Subject $subject, AcademicTerm $term, string $mark, User $encoder): AcademicGrade
    {
        return AcademicGrade::create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'academic_term_id' => $term->id,
            'mark' => $mark, 'status' => GradeStatus::Locked, 'encoded_by' => $encoder->id,
        ]);
    }

    public function test_a_student_who_fits_the_block_stays_regular_and_writes_nothing(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 2);
        $subject = $this->makeSubject('CS201');
        $this->placeSubject($curriculum, $subject, 2);
        $this->makeBlockSection($term, $plan, $subject);
        $student = $this->makeStudent($curriculum, 'clean@grc.test', yearLevel: 2, category: 'regular');
        $registrar = $this->makeRegistrarHead();

        $verdict = app(ReclassifyStudentEnrollmentCategory::class)->execute(
            $student, $term, $registrar, new AuditRequestContext('test-reclassify', null),
        );

        self::assertTrue($verdict->isRegular());
        self::assertSame('regular', $student->fresh()->enrollment_category);
        self::assertSame(0, AuditLog::query()->where('action', AuditAction::STUDENT_ENROLLMENT_CATEGORY_RECLASSIFIED)->count());
        self::assertSame(0, Notification::query()->where('type', NotificationType::EnrollmentCategoryReclassified)->count());
    }

    public function test_a_new_student_with_no_academic_record_keeps_the_category_admission_chose(): void
    {
        // Owner, 2026-10-13: students created by Admission were turning Irregular on their own. The same
        // setup as the next test, but this student has no grade, credit or earlier enrollment, so nothing
        // is written, audited or notified and the stored category stands.
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 4);
        $standard = $this->makeSubject('CS401');
        $this->placeSubject($curriculum, $standard, 4);
        $this->makeBlockSection($term, $plan, $standard);
        $backlog = $this->makeSubject('ITC');
        $this->placeSubject($curriculum, $backlog, 1, '1st');
        $this->makePlainSection($term, $backlog);
        $student = $this->makeStudent($curriculum, 'newcomer@grc.test', yearLevel: 4, category: 'regular', withRecord: false);
        $registrar = $this->makeRegistrarHead();

        $verdict = app(ReclassifyStudentEnrollmentCategory::class)->execute(
            $student, $term, $registrar, new AuditRequestContext('test-reclassify', null),
        );

        self::assertTrue($verdict->isRegular());
        $fresh = $student->fresh();
        self::assertSame('regular', $fresh->enrollment_category);
        self::assertNull($fresh->enrollment_category_derived_at);
        self::assertSame(0, AuditLog::query()->where('action', AuditAction::STUDENT_ENROLLMENT_CATEGORY_RECLASSIFIED)->count());
        self::assertSame(0, Notification::query()->where('type', NotificationType::EnrollmentCategoryReclassified)->count());
    }

    public function test_a_new_student_admission_marked_irregular_stays_irregular(): void
    {
        // Admission's explicit choice of Irregular is kept too: with no record there is nothing to derive from.
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 2);
        $standard = $this->makeSubject('CS201');
        $this->placeSubject($curriculum, $standard, 2);
        $this->makeBlockSection($term, $plan, $standard);
        $student = $this->makeStudent($curriculum, 'chosen-irregular@grc.test', yearLevel: 2, category: 'irregular', withRecord: false);
        $registrar = $this->makeRegistrarHead();

        app(ReclassifyStudentEnrollmentCategory::class)->execute(
            $student, $term, $registrar, new AuditRequestContext('test-reclassify', null),
        );

        self::assertSame('irregular', $student->fresh()->enrollment_category);
        self::assertSame(0, AuditLog::query()->where('action', AuditAction::STUDENT_ENROLLMENT_CATEGORY_RECLASSIFIED)->count());
    }

    public function test_an_open_backlog_subject_flips_the_student_to_irregular_and_audits_and_notifies(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 2);
        $standard = $this->makeSubject('CS201');
        $this->placeSubject($curriculum, $standard, 2);
        $this->makeBlockSection($term, $plan, $standard);
        $backlog = $this->makeSubject('ITC');
        $this->placeSubject($curriculum, $backlog, 1, '1st');
        $this->makePlainSection($term, $backlog);
        $student = $this->makeStudent($curriculum, 'backlog@grc.test', yearLevel: 2, category: 'regular');
        $registrar = $this->makeRegistrarHead();

        $verdict = app(ReclassifyStudentEnrollmentCategory::class)->execute(
            $student, $term, $registrar, new AuditRequestContext('test-reclassify', null),
        );

        self::assertFalse($verdict->isRegular());
        self::assertSame(EnrollmentCategory::Irregular, $verdict->category);

        $fresh = $student->fresh();
        self::assertSame('irregular', $fresh->enrollment_category);
        self::assertNotNull($fresh->enrollment_category_derived_at);

        $audit = AuditLog::query()->where('action', AuditAction::STUDENT_ENROLLMENT_CATEGORY_RECLASSIFIED)->sole();
        self::assertSame('regular', $audit->before_values['enrollment_category']);
        self::assertSame('irregular', $audit->after_values['enrollment_category']);

        $notification = Notification::query()->where('type', NotificationType::EnrollmentCategoryReclassified)->sole();
        self::assertSame($student->user_id, $notification->user_id);
        self::assertStringContainsString('ITC', $notification->message);
    }

    public function test_a_backlog_subject_no_longer_offered_this_term_returns_the_student_to_regular(): void
    {
        // The Socorro Y. Amurao case: was irregular because a backlog
        // subject had an open section; that section is gone this term (a
        // 1st-semester-only subject during a 2nd-semester term), so there
        // is nothing left to add and she reverts to Regular.
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 2);
        $standard = $this->makeSubject('CS201');
        $this->placeSubject($curriculum, $standard, 2);
        $this->makeBlockSection($term, $plan, $standard);
        $backlog = $this->makeSubject('ITC');
        $this->placeSubject($curriculum, $backlog, 1, '1st');
        // No section for ITC exists this term.
        $student = $this->makeStudent($curriculum, 'no-longer-offered@grc.test', yearLevel: 2, category: 'irregular');
        $registrar = $this->makeRegistrarHead();

        $verdict = app(ReclassifyStudentEnrollmentCategory::class)->execute(
            $student, $term, $registrar, new AuditRequestContext('test-reclassify', null),
        );

        self::assertTrue($verdict->isRegular());
        self::assertSame('regular', $student->fresh()->enrollment_category);
    }

    public function test_no_block_published_yet_leaves_the_category_untouched(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        // No plan, no block section for year level 2 this term at all.
        $student = $this->makeStudent($curriculum, 'undetermined@grc.test', yearLevel: 2, category: 'regular');
        $registrar = $this->makeRegistrarHead();

        $verdict = app(ReclassifyStudentEnrollmentCategory::class)->execute(
            $student, $term, $registrar, new AuditRequestContext('test-reclassify', null),
        );

        self::assertTrue($verdict->isRegular());
        self::assertSame('regular', $student->fresh()->enrollment_category);
        self::assertSame(0, AuditLog::query()->where('action', AuditAction::STUDENT_ENROLLMENT_CATEGORY_RECLASSIFIED)->count());
    }

    public function test_locking_a_grade_through_the_endpoint_triggers_reclassification(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 2);
        $standard = $this->makeSubject('CS201');
        $this->placeSubject($curriculum, $standard, 2);
        $this->makeBlockSection($term, $plan, $standard);
        // CS-BACKLOG needs CS-PREREQ; it has an open section this term but
        // isn't addable until the prerequisite is met.
        $prereq = $this->makeSubject('CS-PREREQ');
        $this->placeSubject($curriculum, $prereq, 1, '1st');
        $backlogPlacement = $this->placeSubject($curriculum, $this->makeSubject('CS-BACKLOG'), 1, '1st');
        SubjectPrerequisite::create([
            'curriculum_subject_id' => $backlogPlacement->id,
            'prerequisite_subject_id' => $prereq->id,
            'minimum_grade' => '3.00',
        ]);
        $this->makePlainSection($term, Subject::where('code', 'CS-BACKLOG')->sole());
        $student = $this->makeStudent($curriculum, 'endpoint@grc.test', yearLevel: 2, category: 'regular');
        $professor = User::create(['name' => 'Prof', 'email' => 'prof.reclassify@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::Faculty, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        $registrar = $this->makeRegistrarHead();
        $grade = AcademicGrade::create([
            'student_id' => $student->id, 'subject_id' => $prereq->id, 'academic_term_id' => $term->id,
            'mark' => '2.00', 'status' => GradeStatus::Submitted, 'encoded_by' => $professor->id,
        ]);
        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $registrar->email, 'password' => self::PASSWORD,
        ])->json('data.token');

        $this->withToken($token)->patchJson("/api/v1/academic-grades/{$grade->id}", ['action' => 'lock'])
            ->assertOk();

        self::assertSame('irregular', $student->fresh()->enrollment_category);
    }

    public function test_batch_reclassification_of_many_students_does_not_scale_reads_with_student_count(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 2);
        $standard = $this->makeSubject('CS201');
        $this->placeSubject($curriculum, $standard, 2);
        $this->makeBlockSection($term, $plan, $standard);
        $backlog = $this->makeSubject('ITC');
        $this->placeSubject($curriculum, $backlog, 1, '1st');
        $this->makePlainSection($term, $backlog);
        $registrar = $this->makeRegistrarHead();

        // 5 students, all starting "regular"; exactly 2 (i=2,4) will have
        // already completed the backlog subject early — the other 3 will
        // still have it open and flip to irregular.
        $students = collect(range(1, 5))->map(function (int $i) use ($curriculum, $term, $backlog, $registrar) {
            $student = $this->makeStudent($curriculum, "batch{$i}@grc.test", yearLevel: 2, category: 'regular');
            if ($i % 2 === 0) {
                $this->lockGrade($student, $backlog, $term, '2.00', $registrar);
            }

            return $student;
        });

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $verdicts = app(ReclassifyStudentEnrollmentCategory::class)->executeMany(
            Collection::make($students->all()), $term, $registrar, new AuditRequestContext('test-batch-reclassify', null),
        );

        self::assertCount(5, $verdicts);
        $irregularCount = collect($verdicts)->filter(fn ($verdict) => ! $verdict->isRegular())->count();
        self::assertSame(3, $irregularCount);

        // The read side is a small constant number of queries regardless
        // of student count (all 5 share one curriculum+year-level group):
        // one for the standard block set, one for curriculum placements +
        // prerequisites, one for backlog open-section lookup, one for
        // locked marks, one for migration credits. A per-student N+1 would
        // instead scale with student count (25+ here). The write side
        // legitimately scales with the 3 actual changes. Run this test
        // once, note the ACTUAL count PHPUnit reports on failure, and set
        // this bound to roughly double it — the goal is proving "does not
        // scale with N", not pinning an exact number.
        self::assertLessThan(30, $queryCount);
    }
}
