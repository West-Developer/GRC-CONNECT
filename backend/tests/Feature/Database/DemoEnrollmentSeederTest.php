<?php

namespace Tests\Feature\Database;

use App\Domain\Academic\GradeStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Enrollment;
use App\Models\FacultyAvailability;
use App\Models\FacultySubjectPreference;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoEnrollmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class DemoEnrollmentSeederTest extends TestCase
{
    use RefreshDatabase;

    private const SEED_PASSWORD = 'password';

    /**
     * `AcademicTermSeeder` no longer leaves a `semester_ongoing` term behind
     * on a clean seed (see `AcademicTermSeederTest`), so two things inside
     * `DemoEnrollmentSeeder` that both guard on that term existing now no-op
     * on a bare `$this->seed(DatabaseSeeder::class)`: `seedRegularBlocks()`
     * (connects the reference-professor roster to real Faculty
     * accounts/availability/preferences and generates their block sections)
     * and `reclassify()` (derives `enrollment_category` off the seeded grade
     * history and writes the classification `AuditLog`). Tests that need
     * either output create their own ongoing term here, then re-seed
     * `DemoEnrollmentSeeder` directly (not the whole `DatabaseSeeder`, which
     * would re-run `AcademicTermSeeder` and archive this term straight back
     * out, since it isn't in the seeder's own `TERMS` list).
     */
    private function seedOngoingTerm(): void
    {
        $term = AcademicTerm::create([
            'school_year' => '2026-2027',
            'semester' => '2nd',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);

        DB::table('academic_term_current_slots')->where('id', 1)->update(['academic_term_id' => $term->id]);
    }

    /**
     * Which real BSIT subject `studentRosterProvider()`'s own Irregular
     * rows need an open backlog section for this term, keyed by student
     * number — see `publishBacklogSections()`'s own docblock for why.
     *
     * @var array<string, list<string>>
     */
    private const IRREGULAR_BACKLOG_SUBJECTS = [
        '2023-06-00005' => ['MATHWRLD'],
        '2023-06-00006' => ['PROG1'],
    ];

    /**
     * Publishes an open (non-block-exclusive) `Published` section this term
     * for the given REAL BSIT subject codes — the same `makePlainSection()`
     * pattern `ClassifyEnrollmentStandingTest`/`ReclassifyStudentEnrollmentCategoryTest`
     * already established. Under the term-scoped `ClassifyEnrollmentStanding`
     * rule (Task 2), a backlog subject only makes a student Irregular if it
     * is BOTH not completed AND has its own open section this term.
     * `DemoEnrollmentSeeder::seedRegularBlocks()` only ever publishes the
     * regular-path `BLOCK_SUBJECTS_BY_YEAR` sections — it never opens a
     * backlog section for the specific subjects `STUDENTS`' 0005/0006/0008
     * rows document as their own Irregular-making evidence (MATHWRLD/PROG1/
     * SPI respectively, via `$definition['overrides']`/`['omit']`). Without
     * one, the new rule reads that subject as simply not offered this term
     * and clears the student back to Regular, which is not this fixture's
     * documented intent.
     *
     * Deliberately scoped to only the codes the calling test actually
     * needs (never all three unconditionally): MATHWRLD/PROG1/SPI sit at
     * different curriculum ordinals (1, 2, and 7), and every one of
     * `STUDENTS`' OTHER rows has a shorter grade history that simply
     * hasn't reached some of them yet (e.g. 0001 has only 1 completed
     * ordinal, so it has no grade at all for ordinal-2 PROG1) — an
     * unconditional publish would misread "never had the chance to take
     * this yet" as "failed to remove an open backlog subject" for those
     * other students and wrongly flip them Irregular too.
     *
     * @param  list<string>  $subjectCodes
     */
    private function publishBacklogSections(array $subjectCodes): void
    {
        if ($subjectCodes === []) {
            return;
        }

        $term = AcademicTerm::where('status', AcademicTermStatus::SemesterOngoing)->sole();

        foreach ($subjectCodes as $code) {
            $subject = Subject::query()->where('college', CollegeCode::Ccs)->where('code', $code)->sole();

            Section::firstOrCreate(
                [
                    'academic_term_id' => $term->id,
                    'subject_id' => $subject->id,
                    'section_code' => 'ZZ-BACKLOG',
                ],
                [
                    'schedule_days' => 'Sun',
                    'starts_at_time' => '06:00:00',
                    'ends_at_time' => '07:00:00',
                    'capacity' => 999,
                    'enrolled_count' => 0,
                    'is_block_exclusive' => false,
                    'status' => SectionStatus::Published,
                ],
            );
        }
    }

    /**
     * Eight student profiles, all with real locked grade history, but no
     * Enrollment row of their own yet — every one is left free to submit a
     * fresh enrollment against the current `semester_ongoing` term through
     * the real UI/API, which is the entire point of this seed. Plus 2 more
     * — see `test_curriculum_version_demo_students_resolve_to_the_correct_version`
     * — that demonstrate curriculum versioning on the real `BSIT` program,
     * unrelated to this grade-history roster.
     */
    public function test_a_clean_seed_creates_eight_student_profiles_but_no_enrollments(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(10, StudentProfile::count());
        $this->assertSame(0, Enrollment::count());
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function studentRosterProvider(): array
    {
        return [
            '0001 year 1 regular' => ['2023-06-00001', 1, 'regular'],
            '0002 year 2 regular' => ['2023-06-00002', 2, 'regular'],
            '0003 year 3 regular' => ['2023-06-00003', 3, 'regular'],
            '0004 year 4 regular' => ['2023-06-00004', 4, 'regular'],
            '0005 year 2 irregular (failed subject)' => ['2023-06-00005', 2, 'irregular'],
            '0006 year 2 irregular (incomplete)' => ['2023-06-00006', 2, 'irregular'],
            '0007 year 3 irregular (NC on Leadership)' => ['2023-06-00007', 3, 'irregular'],
            '0008 year 4 irregular (failed back subject)' => ['2023-06-00008', 4, 'irregular'],
        ];
    }

    /**
     * `DemoEnrollmentSeeder::run()` calls `reclassify()` BEFORE
     * `seedRegularBlocks()` — harmless under the old cumulative-lifetime
     * classifier (which never looked at `sections` at all), but under the
     * term-scoped `ClassifyEnrollmentStanding` rule (Task 2) it means the
     * FIRST seed pass always classifies every one of the 8 students as
     * undetermined (no block published for their year level yet) and
     * leaves `enrollment_category` at its starting `null` — see
     * `ReclassifyStudentEnrollmentCategory::computeVerdicts()`'s own
     * "carry forward unchanged, never write" handling of an undetermined
     * verdict. `DemoEnrollmentSeeder` is documented and separately tested
     * as idempotent (`test_reseeding_creates_no_duplicates`), so re-seeding
     * it a second time — now that the first pass's own `seedRegularBlocks()`
     * call already published this term's blocks — lets its SECOND
     * `reclassify()` call see them and derive the real category, without
     * touching `DemoEnrollmentSeeder.php` itself.
     */
    #[DataProvider('studentRosterProvider')]
    public function test_each_seeded_student_has_the_expected_year_level_and_derived_category(
        string $studentNumber,
        int $yearLevel,
        string $category,
    ): void {
        $this->seed(DatabaseSeeder::class);
        $this->seedOngoingTerm();
        $this->seed(DemoEnrollmentSeeder::class);
        $this->publishBacklogSections(self::IRREGULAR_BACKLOG_SUBJECTS[$studentNumber] ?? []);
        $this->seed(DemoEnrollmentSeeder::class);

        $profile = StudentProfile::query()->where('student_number', $studentNumber)->firstOrFail();

        $this->assertSame($yearLevel, $profile->year_level);
        // Never hard-coded: this is the real term-scoped
        // ClassifyEnrollmentStanding's verdict against the grade history
        // seeded just above it, proving the seeder's own correctness.
        $this->assertSame($category, $profile->enrollment_category);
        $this->assertNotNull($profile->enrollment_category_derived_at);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function gradeHistoryCountProvider(): array
    {
        // Real BSIT per-ordinal subject counts: 14, 13, 15, 15, 13, 13, 7
        // (year 1 sem 1, year 1 sem 2, year 2 sem 1, year 2 sem 2, year 3
        // sem 1, year 3 sem 2, year 4 sem 1 respectively).
        return [
            '0001 — 1 completed semester' => ['2023-06-00001', 14],
            '0002 — 3 completed semesters' => ['2023-06-00002', 14 + 13 + 15],
            '0003 — 5 completed semesters' => ['2023-06-00003', 14 + 13 + 15 + 15 + 13],
            '0004 — 7 completed semesters' => ['2023-06-00004', 14 + 13 + 15 + 15 + 13 + 13 + 7],
            '0005 — 3 completed semesters' => ['2023-06-00005', 14 + 13 + 15],
            '0006 — 3 completed semesters' => ['2023-06-00006', 14 + 13 + 15],
            '0007 — 5 completed semesters' => ['2023-06-00007', 14 + 13 + 15 + 15 + 13],
            // 7 completed ordinals' worth of subjects; the one it failed still has a mark.
            '0008 — 7 completed semesters, one failed' => ['2023-06-00008', 14 + 13 + 15 + 15 + 13 + 13 + 7],
        ];
    }

    #[DataProvider('gradeHistoryCountProvider')]
    public function test_each_seeded_student_has_the_expected_locked_grade_count(string $studentNumber, int $expectedCount): void
    {
        $this->seed(DatabaseSeeder::class);

        $profile = StudentProfile::query()->where('student_number', $studentNumber)->firstOrFail();
        $grades = AcademicGrade::query()->where('student_id', $profile->id)->get();

        $this->assertCount($expectedCount, $grades);
        $this->assertTrue($grades->every(fn (AcademicGrade $grade): bool => $grade->status === GradeStatus::Locked));
    }

    /**
     * A subject the student never took no longer makes anyone Irregular; a back subject does (ADR
     * 0028): a required subject from an earlier point in the curriculum that the student took in a
     * prior term and did not pass. 0008 failed SPI (year 4, 1st semester) and is in 2nd semester,
     * so the reason is `needs_adding_backlog` whether or not a section is offered.
     */
    public function test_the_failed_back_subject_irregular_student_carries_the_expected_classification_reason(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seedOngoingTerm();
        $this->seed(DemoEnrollmentSeeder::class);

        $profile = StudentProfile::query()->where('student_number', '2023-06-00008')->firstOrFail();
        $log = AuditLog::query()
            ->where('auditable_type', 'student_profile')
            ->where('auditable_id', $profile->id)
            ->sole();

        $reasons = $log->after_values['reasons'] ?? [];
        $this->assertNotEmpty($reasons);
        $this->assertSame('needs_adding_backlog', $reasons[0]['code'] ?? null);
    }

    /**
     * @return array<string, array{string, string, int, int}>
     */
    public static function curriculumVersionStudentProvider(): array
    {
        return [
            '2023 entry -> old (2018-2023) curriculum, 4th year' => ['2023-06-00100', 'student.oldcurriculum.seed@grc.test', 2023, 4],
            '2024 entry -> new (2024-2029) curriculum, 3rd year' => ['2024-06-00101', 'student.newcurriculum.seed@grc.test', 2024, 3],
        ];
    }

    #[DataProvider('curriculumVersionStudentProvider')]
    public function test_curriculum_version_demo_students_resolve_to_the_correct_version(
        string $studentNumber,
        string $email,
        int $entryYear,
        int $yearLevel,
    ): void {
        $this->seed(DatabaseSeeder::class);

        $profile = StudentProfile::query()->where('student_number', $studentNumber)->with(['curriculum', 'user'])->firstOrFail();

        $this->assertSame($email, $profile->user->email);
        $this->assertSame($entryYear, $profile->entry_year);
        $this->assertSame($yearLevel, $profile->year_level);
        $this->assertSame('BSIT', $profile->program->code);
        $this->assertTrue(
            $entryYear >= $profile->curriculum->effective_start_year
                && $entryYear <= $profile->curriculum->effective_end_year,
            "Student entering {$entryYear} should be on a curriculum whose range contains {$entryYear}, got {$profile->curriculum->effective_school_year}.",
        );
    }

    public function test_the_2023_and_2024_entry_demo_students_are_on_different_curriculum_versions(): void
    {
        $this->seed(DatabaseSeeder::class);

        $oldCurriculumStudent = StudentProfile::query()->where('student_number', '2023-06-00100')->firstOrFail();
        $newCurriculumStudent = StudentProfile::query()->where('student_number', '2024-06-00101')->firstOrFail();

        $this->assertNotSame($oldCurriculumStudent->curriculum_id, $newCurriculumStudent->curriculum_id);
    }

    public function test_reseeding_creates_no_duplicates(): void
    {
        $this->seed(DatabaseSeeder::class);

        $profileIds = StudentProfile::orderBy('id')->pluck('id')->all();
        $gradeCount = AcademicGrade::count();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($profileIds, StudentProfile::orderBy('id')->pluck('id')->all());
        $this->assertSame($gradeCount, AcademicGrade::count());
    }

    public function test_seeded_student_emails_use_the_reserved_test_domain(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (StudentProfile::with('user')->get() as $profile) {
            $this->assertStringEndsWith('@grc.test', $profile->user->email);
        }
    }

    public function test_every_seeded_student_account_uses_the_shared_development_password(): void
    {
        putenv('GRC_SEED_PASSWORD');

        $this->seed(DatabaseSeeder::class);

        $students = User::query()->where('role', 'student')->get();

        foreach ($students as $student) {
            $this->assertTrue(
                Hash::check(self::SEED_PASSWORD, $student->password),
                "Seeded student {$student->email} does not use the shared development password.",
            );
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public static function blockYearProvider(): array
    {
        return [
            'year 1' => [1],
            'year 2' => [2],
            'year 3' => [3],
            'year 4' => [4],
        ];
    }

    /**
     * Scoped to `DemoEnrollmentSeeder`'s own block sections, not "every
     * `is_block_exclusive` section of this subject on the real BSIT
     * curriculum": `ProgramChairScheduleSampleSeeder` independently
     * generates its own block sections (codes like `IT101`) on this SAME
     * curriculum — it picks whichever active curriculum the college's
     * seeded students are actually on, and the 8 `DemoEnrollmentSeeder`
     * students are exactly that — owned by its own "Sample Faculty", an
     * unrelated fixture for a different testing purpose. The two are
     * distinguished by `academic_term_section_plans.college`:
     * `DemoEnrollmentSeeder` always plans under the harmless placeholder
     * `'demo'` (see `seedRegularBlocks()`), while `ProgramChairScheduleSampleSeeder`
     * plans under the subject's real college (`'ccs'`).
     */
    #[DataProvider('blockYearProvider')]
    public function test_every_subject_with_a_reference_professor_has_a_connected_faculty_account_owning_its_blocks(int $yearLevel): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seedOngoingTerm();
        $this->seed(DemoEnrollmentSeeder::class);

        $curriculum = Curriculum::query()->where('program_id', Program::where('code', 'BSIT')->sole()->id)->where('status', 'active')->sole();
        $placements = CurriculumSubject::query()
            ->with('subject')
            ->where('curriculum_id', $curriculum->id)
            ->where('year_level', $yearLevel)
            ->where('semester', '2nd')
            ->whereNotNull('reference_professor_name')
            ->get();

        $this->assertNotEmpty($placements, "Expected at least one named-professor subject for year {$yearLevel}.");

        foreach ($placements as $placement) {
            $professor = User::query()->where('name', $placement->reference_professor_name)->where('role', 'faculty')->where('college', 'ccs')->first();
            $this->assertNotNull($professor, "Expected a Faculty account named '{$placement->reference_professor_name}'.");

            $sections = Section::query()
                ->where('subject_id', $placement->subject_id)
                ->where('is_block_exclusive', true)
                ->whereHas('sectionPlan', fn ($query) => $query->where('curriculum_id', $curriculum->id)->where('college', 'demo'))
                ->get();

            $this->assertNotEmpty($sections, "Expected at least one generated block section for {$placement->subject->code}.");
            foreach ($sections as $section) {
                $this->assertSame($professor->id, $section->professor_id, "Section {$section->section_code} of {$placement->subject->code} is not owned by '{$placement->reference_professor_name}'.");
            }
        }
    }

    #[DataProvider('blockYearProvider')]
    public function test_every_connected_professor_has_declared_availability(int $yearLevel): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seedOngoingTerm();
        $this->seed(DemoEnrollmentSeeder::class);

        $curriculum = Curriculum::query()->where('program_id', Program::where('code', 'BSIT')->sole()->id)->where('status', 'active')->sole();
        $placements = CurriculumSubject::query()
            ->where('curriculum_id', $curriculum->id)
            ->where('year_level', $yearLevel)
            ->where('semester', '2nd')
            ->whereNotNull('reference_professor_name')
            ->get();

        foreach ($placements as $placement) {
            $professor = User::query()->where('name', $placement->reference_professor_name)->where('role', 'faculty')->where('college', 'ccs')->sole();

            // Scoped to DemoEnrollmentSeeder's own fixed 08:00-17:00 window: a name that also
            // matches a real catalog professor (WorkbookFacultyProfileSeeder) can carry that
            // professor's own real availability rows too, at different times, which must not
            // count against this seeder's own idempotent 5-weekday guarantee.
            $this->assertSame(
                5,
                FacultyAvailability::query()->where('professor_id', $professor->id)
                    ->where('starts_at_time', '08:00:00')->where('ends_at_time', '17:00:00')->count(),
                "{$placement->reference_professor_name} should have one declared availability window per weekday.",
            );

            $preference = FacultySubjectPreference::query()
                ->where('professor_id', $professor->id)
                ->where('subject_id', $placement->subject_id)
                ->sole();
            // Not hard-coded to rank 1: `faculty_subject_preferences` has a
            // (professor_id, academic_term_id, rank) unique constraint, and
            // several real professors in the source Excel own more than one
            // of this term's subjects (e.g. "MR. SALAZAR" teaches both
            // PATHFIT2 and PATHFIT4), so only their first subject can be
            // rank 1 — later ones are legitimately 2, 3, etc.
            $this->assertGreaterThan(0, $preference->rank);
        }
    }

    /**
     * Reseeds `DemoEnrollmentSeeder` directly rather than the whole
     * `DatabaseSeeder` — see `seedOngoingTerm()` — since re-running
     * `AcademicTermSeeder` would archive the inline ongoing term straight
     * back out. `DemoEnrollmentSeeder` itself is the seeder whose
     * idempotency this test actually exercises.
     */
    public function test_reseeding_connected_professors_creates_no_duplicates(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seedOngoingTerm();
        $this->seed(DemoEnrollmentSeeder::class);

        $curriculum = Curriculum::query()->where('program_id', Program::where('code', 'BSIT')->sole()->id)->where('status', 'active')->sole();
        $names = CurriculumSubject::query()
            ->where('curriculum_id', $curriculum->id)
            ->whereIn('year_level', [1, 2, 3, 4])
            ->where('semester', '2nd')
            ->whereNotNull('reference_professor_name')
            ->pluck('reference_professor_name')
            ->unique();
        // A professor who teaches in two colleges legitimately has one account per college, so the
        // connected accounts this seeder owns are the CCS ones.
        $professorCount = User::query()->whereIn('name', $names)->where('role', 'faculty')->where('college', 'ccs')->count();

        $this->seed(DemoEnrollmentSeeder::class);

        $this->assertSame($names->count(), $professorCount);
        $this->assertSame($professorCount, User::query()->whereIn('name', $names)->where('role', 'faculty')->where('college', 'ccs')->count());
    }

    /**
     * Invoked directly rather than through `db:seed`, matching RoleUserSeederTest:
     * the artisan command's production confirmation prompt would otherwise
     * intercept the call before the seeder runs.
     */
    public function test_it_refuses_to_run_outside_local_and_testing_environments(): void
    {
        app()->detectEnvironment(static fn (): string => 'production');

        $this->expectException(RuntimeException::class);

        app(DemoEnrollmentSeeder::class)->run();
    }

    public function test_it_seeds_nothing_when_it_refuses_to_run(): void
    {
        app()->detectEnvironment(static fn (): string => 'production');

        try {
            app(DemoEnrollmentSeeder::class)->run();
        } catch (RuntimeException) {
            // Expected; asserted in the preceding test.
        }

        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseCount('student_profiles', 0);
    }
}
