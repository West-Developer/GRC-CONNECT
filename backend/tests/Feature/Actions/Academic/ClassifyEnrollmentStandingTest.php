<?php

namespace Tests\Feature\Actions\Academic;

use App\Actions\Academic\ClassifyEnrollmentStanding;
use App\Domain\Academic\GradeStatus;
use App\Domain\Academic\TransfereeCreditStatus;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Enrollment\ClassificationVerdict;
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
use App\Models\Curriculum;
use App\Models\CurriculumMigration;
use App\Models\CurriculumMigrationCredit;
use App\Models\CurriculumSubject;
use App\Models\CurriculumSubjectEquivalency;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\SubjectPrerequisite;
use App\Models\TransfereeCredit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClassifyEnrollmentStandingTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function makeTerm(string $semester = '2nd'): AcademicTerm
    {
        return AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => $semester,
            'status' => AcademicTermStatus::SemesterOngoing,
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
     * places, so they are a student the system knows (owner, 2026-10-13: a student with no record at all is not
     * classified) without that result touching any rule under test. Pass false for a brand-new student.
     */
    private function makeStudent(Curriculum $curriculum, string $email, int $yearLevel = 2, bool $withRecord = true): StudentProfile
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
            $this->lockGrade($student, $anchorSubject, $anchorTerm, '2.00');
        }

        return $student;
    }

    private function makePlan(AcademicTerm $term, Curriculum $curriculum, int $yearLevel): AcademicTermSectionPlan
    {
        return AcademicTermSectionPlan::create([
            'academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id,
            'college' => 'ccs', 'year_level' => $yearLevel, 'section_count' => 1,
            'students_per_block' => 40, 'status' => 'submitted',
        ]);
    }

    private function makeBlockSection(AcademicTerm $term, AcademicTermSectionPlan $plan, Subject $subject, string $blockCode = 'IT201'): Section
    {
        return Section::create([
            'academic_term_id' => $term->id, 'section_plan_id' => $plan->id, 'subject_id' => $subject->id,
            'section_code' => $blockCode, 'schedule_days' => 'MWF', 'starts_at_time' => '08:00:00',
            'ends_at_time' => '09:00:00', 'capacity' => 40, 'is_block_exclusive' => true,
            'status' => SectionStatus::Published,
        ]);
    }

    private function makePlainSection(AcademicTerm $term, Subject $subject, array $overrides = []): Section
    {
        return Section::create(array_merge([
            'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'section_code' => 'A',
            'capacity' => 40, 'is_block_exclusive' => false, 'status' => SectionStatus::Published,
        ], $overrides));
    }

    private function lockGrade(StudentProfile $student, Subject $subject, AcademicTerm $term, string $mark): AcademicGrade
    {
        $encoder = User::create([
            'name' => 'Encoder', 'email' => 'encoder.'.uniqid().'@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::RegistrarHead, 'status' => UserStatus::Active,
        ]);

        return AcademicGrade::create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'academic_term_id' => $term->id,
            'mark' => $mark, 'status' => GradeStatus::Locked, 'encoded_by' => $encoder->id,
        ]);
    }

    public function test_a_student_who_fits_the_standard_block_exactly_is_regular(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 2);
        $subject = $this->makeSubject('CS201');
        $this->placeSubject($curriculum, $subject, 2);
        $this->makeBlockSection($term, $plan, $subject);
        $student = $this->makeStudent($curriculum, 'fits@grc.test');

        $verdict = app(ClassifyEnrollmentStanding::class)->classify($student, $term);

        self::assertNotNull($verdict);
        self::assertTrue($verdict->isRegular());
    }

    public function test_a_backlog_subject_with_an_open_section_this_term_makes_the_student_irregular(): void
    {
        $priorTerm = $this->makeTerm('1st');
        $term = $this->makeTerm('2nd');
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 2);
        $standard = $this->makeSubject('CS201');
        $this->placeSubject($curriculum, $standard, 2);
        $this->makeBlockSection($term, $plan, $standard);
        $backlog = $this->makeSubject('ITC');
        $this->placeSubject($curriculum, $backlog, 1, '1st');
        $this->makePlainSection($term, $backlog);
        $student = $this->makeStudent($curriculum, 'backlog-open@grc.test');
        // Student previously took and failed the subject in a prior term
        $this->lockGrade($student, $backlog, $priorTerm, '5.00');

        $verdict = app(ClassifyEnrollmentStanding::class)->classify($student, $term);

        self::assertNotNull($verdict);
        self::assertFalse($verdict->isRegular());
        self::assertSame('needs_adding_backlog', $verdict->reasons[0]['code']);
        self::assertStringContainsString('ITC', $verdict->reasons[0]['message']);
    }

    public function test_a_student_with_no_failed_grades_remains_regular_even_if_prior_subject_has_open_section(): void
    {
        $term = $this->makeTerm('2nd');
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 1);
        $standard = $this->makeSubject('CS102');
        $this->placeSubject($curriculum, $standard, 1, '2nd');
        $this->makeBlockSection($term, $plan, $standard);
        $priorSubject = $this->makeSubject('CS101');
        $this->placeSubject($curriculum, $priorSubject, 1, '1st');
        $this->makePlainSection($term, $priorSubject);
        $student = $this->makeStudent($curriculum, 'no-failures@grc.test', yearLevel: 1);
        // Student has no failed grades

        $verdict = app(ClassifyEnrollmentStanding::class)->classify($student, $term);

        self::assertNotNull($verdict);
        self::assertTrue($verdict->isRegular(), 'Student with no failed grades should remain regular');
    }

    public function test_a_future_subject_with_an_open_section_this_term_does_not_affect_standing(): void
    {
        // The inverse of the "open backlog subject" case above: SPI is
        // placed at year 4, well ahead of this year-2 student's own
        // current position. Even though it has an open, non-block-exclusive
        // section this term and no unmet prerequisite blocks it, a subject
        // the student hasn't reached yet must never be flagged as
        // needs_adding_backlog.
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 2);
        $standard = $this->makeSubject('CS201');
        $this->placeSubject($curriculum, $standard, 2);
        $this->makeBlockSection($term, $plan, $standard);
        $future = $this->makeSubject('SPI');
        $this->placeSubject($curriculum, $future, 4, '2nd');
        $this->makePlainSection($term, $future);
        $student = $this->makeStudent($curriculum, 'future-subject@grc.test');

        $verdict = app(ClassifyEnrollmentStanding::class)->classify($student, $term);

        self::assertNotNull($verdict);
        self::assertTrue($verdict->isRegular());
    }

    public function test_a_backlog_subject_with_no_section_this_term_does_not_affect_standing(): void
    {
        // The exact Socorro Y. Amurao case: a backlog subject exists, but
        // it isn't offered this (2nd semester) term, so there is nothing
        // actionable for it right now.
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 2);
        $standard = $this->makeSubject('CS201');
        $this->placeSubject($curriculum, $standard, 2);
        $this->makeBlockSection($term, $plan, $standard);
        $backlog = $this->makeSubject('ITC');
        $this->placeSubject($curriculum, $backlog, 1, '1st');
        // No section created for ITC this term at all.
        $student = $this->makeStudent($curriculum, 'backlog-closed@grc.test');

        $verdict = app(ClassifyEnrollmentStanding::class)->classify($student, $term);

        self::assertNotNull($verdict);
        self::assertTrue($verdict->isRegular());
    }

    public function test_a_failed_backlog_subject_makes_the_student_irregular_even_with_no_section_this_term(): void
    {
        // ADR 0028 (Doc 12): a subject the student took and failed in an earlier
        // term is a back subject whether or not a section happens to be offered
        // this term. Contrast with the Amurao case above, where the subject was
        // never taken at all.
        $priorTerm = $this->makeTerm('1st');
        $term = $this->makeTerm('2nd');
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 2);
        $standard = $this->makeSubject('CS201');
        $this->placeSubject($curriculum, $standard, 2);
        $this->makeBlockSection($term, $plan, $standard);
        $backlog = $this->makeSubject('ETHICS');
        $this->placeSubject($curriculum, $backlog, 1, '1st');
        // No section for ETHICS this term.
        $student = $this->makeStudent($curriculum, 'failed-no-section@grc.test');
        $this->lockGrade($student, $backlog, $priorTerm, '5.00');

        $verdict = app(ClassifyEnrollmentStanding::class)->classify($student, $term);

        self::assertNotNull($verdict);
        self::assertFalse($verdict->isRegular());
        self::assertSame('needs_adding_backlog', $verdict->reasons[0]['code']);
        self::assertStringContainsString('ETHICS', $verdict->reasons[0]['message']);
        self::assertStringContainsString('back subject', $verdict->reasons[0]['message']);
    }

    public function test_a_failed_backlog_subject_is_irregular_even_when_no_block_is_published_yet(): void
    {
        // Undetermined (null) only means "we cannot tell yet"; a failed
        // back subject is already enough to tell.
        $priorTerm = $this->makeTerm('1st');
        $term = $this->makeTerm('2nd');
        $curriculum = $this->makeCurriculum();
        $backlog = $this->makeSubject('ETHICS');
        $this->placeSubject($curriculum, $backlog, 1, '1st');
        $student = $this->makeStudent($curriculum, 'failed-no-block@grc.test');
        $this->lockGrade($student, $backlog, $priorTerm, '5.00');

        $verdict = app(ClassifyEnrollmentStanding::class)->classify($student, $term);

        self::assertNotNull($verdict);
        self::assertFalse($verdict->isRegular());
    }

    public function test_a_year_one_student_who_failed_a_first_semester_subject_is_irregular_in_the_second_semester(): void
    {
        // Doc 7: "kapag may bagsak na subject dapat next sem na sya magiging irregular".
        $priorTerm = $this->makeTerm('1st');
        $term = $this->makeTerm('2nd');
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 1);
        $standard = $this->makeSubject('CS102');
        $this->placeSubject($curriculum, $standard, 1, '2nd');
        $this->makeBlockSection($term, $plan, $standard);
        $failed = $this->makeSubject('CS101');
        $this->placeSubject($curriculum, $failed, 1, '1st');
        $student = $this->makeStudent($curriculum, 'failed-first-sem@grc.test', yearLevel: 1);
        $this->lockGrade($student, $failed, $priorTerm, '5.00');

        $verdict = app(ClassifyEnrollmentStanding::class)->classify($student, $term);

        self::assertNotNull($verdict);
        self::assertFalse($verdict->isRegular());
    }

    public function test_a_failure_recorded_in_the_current_term_does_not_flip_the_student_mid_term(): void
    {
        // Locking a grade during the term it belongs to must never change the
        // student's standing for that same term (Doc 7); it counts from the next term.
        $term = $this->makeTerm('2nd');
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 2);
        $standard = $this->makeSubject('CS201');
        $this->placeSubject($curriculum, $standard, 2);
        $this->makeBlockSection($term, $plan, $standard);
        $backlog = $this->makeSubject('ETHICS');
        $this->placeSubject($curriculum, $backlog, 1, '1st');
        $student = $this->makeStudent($curriculum, 'failed-this-term@grc.test');
        $this->lockGrade($student, $backlog, $term, '5.00');

        $verdict = app(ClassifyEnrollmentStanding::class)->classify($student, $term);

        self::assertNotNull($verdict);
        self::assertTrue($verdict->isRegular());
    }

    public function test_a_failed_subject_that_was_later_passed_is_not_a_back_subject(): void
    {
        $firstTerm = $this->makeTerm('1st');
        $secondTerm = AcademicTerm::create([
            'school_year' => '2027-2028', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        $term = AcademicTerm::create([
            'school_year' => '2027-2028', 'semester' => '2nd', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 2);
        $standard = $this->makeSubject('CS201');
        $this->placeSubject($curriculum, $standard, 2);
        $this->makeBlockSection($term, $plan, $standard);
        $retaken = $this->makeSubject('ETHICS');
        $this->placeSubject($curriculum, $retaken, 1, '1st');
        $student = $this->makeStudent($curriculum, 'failed-then-passed@grc.test');
        $this->lockGrade($student, $retaken, $firstTerm, '5.00');
        $this->lockGrade($student, $retaken, $secondTerm, '2.00');

        $verdict = app(ClassifyEnrollmentStanding::class)->classify($student, $term);

        self::assertNotNull($verdict);
        self::assertTrue($verdict->isRegular());
    }

    public function test_a_failed_subject_that_is_part_of_this_terms_block_is_a_repeat_not_a_back_subject(): void
    {
        // A dual-semester subject failed in the 1st semester and taken again
        // inside this term's block is simply being repeated in the block the
        // student already follows; it does not make them Irregular.
        $priorTerm = $this->makeTerm('1st');
        $term = $this->makeTerm('2nd');
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 1);
        $subject = $this->makeSubject('E-COMM');
        $this->placeSubject($curriculum, $subject, 1, '1st|2nd');
        $this->makeBlockSection($term, $plan, $subject);
        $student = $this->makeStudent($curriculum, 'failed-repeat-in-block@grc.test', yearLevel: 1);
        $this->lockGrade($student, $subject, $priorTerm, '5.00');

        $verdict = app(ClassifyEnrollmentStanding::class)->classify($student, $term);

        self::assertNotNull($verdict);
        self::assertTrue($verdict->isRegular());
    }

    public function test_a_standard_subject_already_passed_early_makes_student_irregular(): void
    {
        $priorTerm = $this->makeTerm('1st');
        $term = $this->makeTerm('2nd');
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 2);
        $subject = $this->makeSubject('CS201');
        $this->placeSubject($curriculum, $subject, 2);
        $this->makeBlockSection($term, $plan, $subject);
        $student = $this->makeStudent($curriculum, 'early-pass@grc.test');
        $this->lockGrade($student, $subject, $priorTerm, '2.00');

        $verdict = app(ClassifyEnrollmentStanding::class)->classify($student, $term);

        self::assertNotNull($verdict);
        self::assertFalse($verdict->isRegular());
        self::assertSame('needs_removing_completed', $verdict->reasons[0]['code']);
    }

    public function test_a_dual_semester_standard_subject_already_passed_does_not_affect_standing(): void
    {
        // A subject placed '1st|2nd' (SemesterCoverage::coversBoth()) is
        // offered either semester by design (App\Domain\Curriculum\
        // SemesterCoverage's own docblock: written by
        // CatalogSectionMetadata::semester() for exactly this case).
        // Having already passed it in an earlier semester is the NORMAL,
        // expected outcome of that flexibility -- not an anomaly worth
        // flagging for removal. Real-data regression: this exact pattern
        // (E-COMM, curriculum_id=10) misclassified the large majority of
        // this term's Irregular population after the population-wide
        // reclassify, including a student with no genuine backlog subject
        // at all (Ernesto F. Ward).
        $priorTerm = $this->makeTerm('1st');
        $term = $this->makeTerm('2nd');
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 2);
        $subject = $this->makeSubject('E-COMM');
        $this->placeSubject($curriculum, $subject, 2, '1st|2nd');
        $this->makeBlockSection($term, $plan, $subject);
        $student = $this->makeStudent($curriculum, 'flexible-early-pass@grc.test');
        $this->lockGrade($student, $subject, $priorTerm, '2.00');

        $verdict = app(ClassifyEnrollmentStanding::class)->classify($student, $term);

        self::assertNotNull($verdict);
        self::assertTrue($verdict->isRegular());
    }

    public function test_a_standard_subject_blocked_by_an_unmet_prerequisite_makes_the_student_irregular(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 2);
        $prereq = $this->makeSubject('CS101');
        $this->placeSubject($curriculum, $prereq, 1, '1st');
        $advanced = $this->makeSubject('CS201');
        $placement = $this->placeSubject($curriculum, $advanced, 2);
        SubjectPrerequisite::create([
            'curriculum_subject_id' => $placement->id, 'prerequisite_subject_id' => $prereq->id, 'minimum_grade' => '3.00',
        ]);
        $this->makeBlockSection($term, $plan, $advanced);
        $student = $this->makeStudent($curriculum, 'unmet-prereq@grc.test');
        // CS101 never taken.

        $verdict = app(ClassifyEnrollmentStanding::class)->classify($student, $term);

        self::assertNotNull($verdict);
        self::assertFalse($verdict->isRegular());
        self::assertSame('needs_removing_prerequisite', $verdict->reasons[0]['code']);
    }

    public function test_no_block_published_yet_for_the_year_level_is_undetermined(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        // No plan, no block section at all for year level 2 this term.
        $student = $this->makeStudent($curriculum, 'no-block@grc.test');

        $verdict = app(ClassifyEnrollmentStanding::class)->classify($student, $term);

        self::assertNull($verdict);
    }

    public function test_a_migration_credit_on_a_backlog_subject_is_not_counted_as_needing_addition(): void
    {
        $term = $this->makeTerm();
        $target = $this->makeCurriculum();
        $source = Curriculum::create([
            'program_id' => $target->program_id, 'name' => 'BSCS Previous Curriculum',
            'effective_school_year' => '2023-2024', 'status' => CurriculumStatus::Archived,
        ]);
        $plan = $this->makePlan($term, $target, 2);
        $standard = $this->makeSubject('CS201');
        $this->placeSubject($target, $standard, 2);
        $this->makeBlockSection($term, $plan, $standard);
        $oldSubject = $this->makeSubject('CS-OLD');
        $newSubject = $this->makeSubject('CS-NEW');
        $this->placeSubject($source, $oldSubject, 1, '1st');
        $this->placeSubject($target, $newSubject, 1, '1st');
        $this->makePlainSection($term, $newSubject);
        $student = $this->makeStudent($target, 'credited-backlog@grc.test');
        $registrar = User::create([
            'name' => 'Registrar', 'email' => 'registrar.credit@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::RegistrarHead, 'status' => UserStatus::Active,
        ]);
        $grade = $this->lockGrade($student, $oldSubject, $term, '2.00');
        $equivalency = CurriculumSubjectEquivalency::create([
            'source_curriculum_id' => $source->id, 'target_curriculum_id' => $target->id,
            'source_subject_id' => $oldSubject->id, 'target_subject_id' => $newSubject->id,
        ]);
        $migration = CurriculumMigration::create([
            'student_id' => $student->id, 'source_curriculum_id' => $source->id,
            'target_curriculum_id' => $target->id, 'processed_by' => $registrar->id, 'migrated_at' => now(),
        ]);
        CurriculumMigrationCredit::create([
            'curriculum_migration_id' => $migration->id, 'curriculum_subject_equivalency_id' => $equivalency->id,
            'source_academic_grade_id' => $grade->id, 'target_subject_id' => $newSubject->id,
        ]);

        $verdict = app(ClassifyEnrollmentStanding::class)->classify($student, $term);

        self::assertNotNull($verdict);
        self::assertTrue($verdict->isRegular());
    }

    private function studentWithACreditedBacklogSubject(TransfereeCreditStatus $status): ClassificationVerdict
    {
        $term = $this->makeTerm();
        $target = $this->makeCurriculum();
        $plan = $this->makePlan($term, $target, 2);
        $standard = $this->makeSubject('CS201');
        $this->placeSubject($target, $standard, 2);
        $this->makeBlockSection($term, $plan, $standard);
        $backlog = $this->makeSubject('CS-XFER');
        $this->placeSubject($target, $backlog, 1, '1st');
        $this->makePlainSection($term, $backlog);
        $student = $this->makeStudent($target, 'transferee-backlog-'.$status->value.'@grc.test');
        TransfereeCredit::create([
            'student_id' => $student->id, 'source_institution' => 'Other University',
            'source_subject_code' => 'EXT101', 'source_subject_title' => 'Programming',
            'credited_units' => 3, 'subject_id' => $backlog->id, 'status' => $status,
        ]);

        $verdict = app(ClassifyEnrollmentStanding::class)->classify($student, $term);
        self::assertNotNull($verdict);

        return $verdict;
    }

    public function test_an_approved_transferee_credit_on_a_backlog_subject_is_not_counted_as_needing_addition(): void
    {
        self::assertTrue($this->studentWithACreditedBacklogSubject(TransfereeCreditStatus::Approved)->isRegular());
    }

    /**
     * Owner, 2026-10-15: a credit the Program Chair passed to the Registrar Head counts for the student
     * already, provisionally, so they can enrol on it; the Head's approval only makes it final.
     */
    public function test_a_transferee_credit_the_chair_passed_on_already_clears_a_backlog_subject_provisionally(): void
    {
        self::assertTrue($this->studentWithACreditedBacklogSubject(TransfereeCreditStatus::Endorsed)->isRegular());
    }

    public function test_a_transferee_credit_still_with_the_program_chair_does_not_clear_a_backlog_subject(): void
    {
        self::assertFalse($this->studentWithACreditedBacklogSubject(TransfereeCreditStatus::Pending)->isRegular());
    }

    /**
     * A transferee whose block (the standard set the Program Chair published for their year level) has
     * a subject the student may already be credited for: CS201 is in the block, and so is CS202.
     */
    private function studentWithACreditOnABlockSubject(TransfereeCreditStatus $status): ClassificationVerdict
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 2);
        $credited = $this->makeSubject('CS201');
        $other = $this->makeSubject('CS202');
        foreach ([$credited, $other] as $subject) {
            $this->placeSubject($curriculum, $subject, 2);
            $this->makeBlockSection($term, $plan, $subject);
        }
        $student = $this->makeStudent($curriculum, 'block-credit-'.$status->value.'@grc.test');
        TransfereeCredit::create([
            'student_id' => $student->id, 'source_institution' => 'Other University',
            'source_subject_code' => 'EXT201', 'source_subject_title' => 'Data Structures',
            'credited_units' => 3, 'subject_id' => $credited->id, 'status' => $status,
        ]);

        $verdict = app(ClassifyEnrollmentStanding::class)->classify($student, $term);
        self::assertNotNull($verdict);

        return $verdict;
    }

    /**
     * Owner, 2026-10-15 (a student credited for NSTP 1 was still enrolled as Regular and offered the whole
     * block): a block subject the student is already credited for cannot be taken again, so they enrol per
     * subject as an Irregular student. A subject passed with a grade keeps its own rule (see the tests above).
     */
    public function test_an_approved_credit_on_a_block_subject_makes_the_student_irregular(): void
    {
        $verdict = $this->studentWithACreditOnABlockSubject(TransfereeCreditStatus::Approved);

        self::assertFalse($verdict->isRegular());
        self::assertSame(['needs_removing_credited'], array_column($verdict->reasons, 'code'));
        self::assertStringContainsString('CS201', $verdict->reasons[0]['message']);
        self::assertStringContainsString('credited', $verdict->reasons[0]['message']);
    }

    public function test_a_credit_the_chair_passed_on_makes_the_student_irregular_before_the_head_decides(): void
    {
        self::assertFalse($this->studentWithACreditOnABlockSubject(TransfereeCreditStatus::Endorsed)->isRegular());
    }

    public function test_a_credit_still_with_the_program_chair_leaves_the_student_regular(): void
    {
        self::assertTrue($this->studentWithACreditOnABlockSubject(TransfereeCreditStatus::Pending)->isRegular());
    }

    public function test_a_credit_the_registrar_head_rejected_leaves_the_student_regular(): void
    {
        self::assertTrue($this->studentWithACreditOnABlockSubject(TransfereeCreditStatus::Rejected)->isRegular());
    }

    public function test_a_curriculum_migration_credit_on_a_block_subject_makes_a_returnee_irregular(): void
    {
        $term = $this->makeTerm();
        $target = $this->makeCurriculum();
        $source = Curriculum::create([
            'program_id' => $target->program_id, 'name' => 'BSCS Previous Curriculum',
            'effective_school_year' => '2023-2024', 'status' => CurriculumStatus::Archived,
        ]);
        $plan = $this->makePlan($term, $target, 2);
        $newSubject = $this->makeSubject('CS201');
        $oldSubject = $this->makeSubject('CS-OLD');
        $this->placeSubject($target, $newSubject, 2);
        $this->placeSubject($source, $oldSubject, 2);
        $this->makeBlockSection($term, $plan, $newSubject);
        $student = $this->makeStudent($target, 'returnee-block-credit@grc.test');
        $registrar = User::create([
            'name' => 'Registrar', 'email' => 'registrar.block.credit@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::RegistrarHead, 'status' => UserStatus::Active,
        ]);
        $priorTerm = $this->makeTerm('1st');
        $grade = $this->lockGrade($student, $oldSubject, $priorTerm, '2.00');
        $equivalency = CurriculumSubjectEquivalency::create([
            'source_curriculum_id' => $source->id, 'target_curriculum_id' => $target->id,
            'source_subject_id' => $oldSubject->id, 'target_subject_id' => $newSubject->id,
        ]);
        $migration = CurriculumMigration::create([
            'student_id' => $student->id, 'source_curriculum_id' => $source->id,
            'target_curriculum_id' => $target->id, 'processed_by' => $registrar->id, 'migrated_at' => now(),
        ]);
        CurriculumMigrationCredit::create([
            'curriculum_migration_id' => $migration->id, 'curriculum_subject_equivalency_id' => $equivalency->id,
            'source_academic_grade_id' => $grade->id, 'target_subject_id' => $newSubject->id,
        ]);

        $verdict = app(ClassifyEnrollmentStanding::class)->classify($student, $term);

        self::assertNotNull($verdict);
        self::assertFalse($verdict->isRegular());
        self::assertSame(['needs_removing_credited'], array_column($verdict->reasons, 'code'));
    }

    public function test_classify_many_batches_across_students_sharing_curriculum_and_year_level(): void
    {
        $priorTerm = $this->makeTerm('1st');
        $term = $this->makeTerm('2nd');
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 2);
        $subject = $this->makeSubject('CS201');
        $placement = $this->placeSubject($curriculum, $subject, 2);
        $prereq = $this->makeSubject('CS100');
        $this->placeSubject($curriculum, $prereq, 1, '1st');
        SubjectPrerequisite::create([
            'curriculum_subject_id' => $placement->id, 'prerequisite_subject_id' => $prereq->id, 'minimum_grade' => '3.00',
        ]);
        $this->makeBlockSection($term, $plan, $subject);
        $fits = $this->makeStudent($curriculum, 'many-fits@grc.test');
        $this->lockGrade($fits, $prereq, $priorTerm, '2.00');
        $blocked = $this->makeStudent($curriculum, 'many-blocked@grc.test');

        $verdicts = app(ClassifyEnrollmentStanding::class)->classifyMany(
            new Collection([$fits, $blocked]),
            $term,
        );

        self::assertTrue($verdicts[$fits->id]->isRegular());
        self::assertFalse($verdicts[$blocked->id]->isRegular());
    }

    /**
     * A year-4 student whose block subject needs a prerequisite and whose earlier subject has an open
     * section: with no record in the system either rule would call them Irregular.
     */
    private function yearFourStudentWhoLooksBehind(AcademicTerm $term, string $email): StudentProfile
    {
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 4);
        $prereq = $this->makeSubject('CS101');
        $this->placeSubject($curriculum, $prereq, 1, '1st');
        $this->makePlainSection($term, $prereq);
        $advanced = $this->makeSubject('CS401');
        $placement = $this->placeSubject($curriculum, $advanced, 4);
        SubjectPrerequisite::create([
            'curriculum_subject_id' => $placement->id, 'prerequisite_subject_id' => $prereq->id, 'minimum_grade' => '3.00',
        ]);
        $this->makeBlockSection($term, $plan, $advanced);

        return $this->makeStudent($curriculum, $email, yearLevel: 4, withRecord: false);
    }

    private function enrollInTerm(StudentProfile $student, AcademicTerm $term, EnrollmentStatus $status = EnrollmentStatus::Enrolled): Enrollment
    {
        return Enrollment::create([
            'student_id' => $student->id, 'academic_term_id' => $term->id,
            'status' => $status, 'total_units' => 3, 'submitted_at' => now(),
        ]);
    }

    public function test_a_new_student_with_no_academic_record_is_undetermined_not_irregular(): void
    {
        // Owner, 2026-10-13: a student Admission has just created must not become Irregular on their own.
        // Nothing in the system says they have taken or failed anything, so there is no evidence either way.
        $term = $this->makeTerm();
        $student = $this->yearFourStudentWhoLooksBehind($term, 'brand-new@grc.test');

        self::assertNull(app(ClassifyEnrollmentStanding::class)->classify($student, $term));
    }

    public function test_a_student_enrolled_only_in_the_current_term_still_has_no_record(): void
    {
        // Enrolling this term is not history: it is the very enrollment being classified.
        $term = $this->makeTerm();
        $student = $this->yearFourStudentWhoLooksBehind($term, 'this-term-only@grc.test');
        $this->enrollInTerm($student, $term);

        self::assertNull(app(ClassifyEnrollmentStanding::class)->classify($student, $term));
    }

    public function test_a_student_enrolled_in_an_earlier_term_has_a_record_so_standing_is_derived_as_before(): void
    {
        $earlier = AcademicTerm::create([
            'school_year' => '2025-2026', 'semester' => '2nd', 'status' => AcademicTermStatus::Archived,
        ]);
        $term = $this->makeTerm();
        $student = $this->yearFourStudentWhoLooksBehind($term, 'earlier-term@grc.test');
        $this->enrollInTerm($student, $earlier);

        $verdict = app(ClassifyEnrollmentStanding::class)->classify($student, $term);

        self::assertNotNull($verdict);
        self::assertFalse($verdict->isRegular());
    }

    public function test_a_student_whose_earlier_enrollment_was_withdrawn_has_no_record(): void
    {
        $earlier = AcademicTerm::create([
            'school_year' => '2025-2026', 'semester' => '2nd', 'status' => AcademicTermStatus::Archived,
        ]);
        $term = $this->makeTerm();
        $student = $this->yearFourStudentWhoLooksBehind($term, 'withdrawn@grc.test');
        $this->enrollInTerm($student, $earlier, EnrollmentStatus::Withdrawn);

        self::assertNull(app(ClassifyEnrollmentStanding::class)->classify($student, $term));
    }

    public function test_classify_many_leaves_only_the_student_without_a_record_undetermined(): void
    {
        $priorTerm = $this->makeTerm('1st');
        $term = $this->makeTerm('2nd');
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 2);
        $subject = $this->makeSubject('CS201');
        $placement = $this->placeSubject($curriculum, $subject, 2);
        $prereq = $this->makeSubject('CS100');
        $this->placeSubject($curriculum, $prereq, 1, '1st');
        $other = $this->makeSubject('CS150');
        $this->placeSubject($curriculum, $other, 1, '1st');
        SubjectPrerequisite::create([
            'curriculum_subject_id' => $placement->id, 'prerequisite_subject_id' => $prereq->id, 'minimum_grade' => '3.00',
        ]);
        $this->makeBlockSection($term, $plan, $subject);
        $withRecord = $this->makeStudent($curriculum, 'many-record@grc.test');
        $this->lockGrade($withRecord, $other, $priorTerm, '2.00'); // has a record, but not the prerequisite
        $newcomer = $this->makeStudent($curriculum, 'many-newcomer@grc.test', withRecord: false);

        $verdicts = app(ClassifyEnrollmentStanding::class)->classifyMany(new Collection([$withRecord, $newcomer]), $term);

        self::assertFalse($verdicts[$withRecord->id]->isRegular());
        self::assertNull($verdicts[$newcomer->id]);
    }

    public function test_locked_grades_in_current_term_do_not_make_student_irregular(): void
    {
        $term = $this->makeTerm('1st');
        $curriculum = $this->makeCurriculum();
        $plan = $this->makePlan($term, $curriculum, 1);
        $subject = $this->makeSubject('IT101');
        $this->placeSubject($curriculum, $subject, 1, '1st');
        $this->makeBlockSection($term, $plan, $subject, 'IT101-SEC');
        $student = $this->makeStudent($curriculum, 'current-term-grades@grc.test', 1);

        // Grade is encoded and locked for the current term enrollment
        $this->lockGrade($student, $subject, $term, '1.25');

        $verdict = app(ClassifyEnrollmentStanding::class)->classify($student, $term);

        self::assertNotNull($verdict);
        self::assertTrue($verdict->isRegular(), 'Student with locked grades in the current term should remain regular for this term');
    }
}
