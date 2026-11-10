<?php

namespace Tests\Feature\Database;

use App\Actions\Academic\ClassifyEnrollmentStanding;
use App\Actions\Academic\ReclassifyStudentEnrollmentCategory;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Scheduling\SectionConflictDetector;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\Program;
use App\Models\RoomCatalogEntry;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\AcademicTermSeeder;
use Database\Seeders\StudentRosterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

final class StudentRosterSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array{code: string, college: CollegeCode}> */
    private const PROGRAMS = [
        'BSBA-FM' => ['code' => 'BSBA-FM', 'college' => CollegeCode::Cbae],
        'BSA' => ['code' => 'BSA', 'college' => CollegeCode::Coa],
        'BSIT' => ['code' => 'BSIT', 'college' => CollegeCode::Ccs],
    ];

    /**
     * (curriculum code) => list of {year_level, semester, subject_code}
     * needed for `sections`/`academic_term_section_plans` generation to have
     * something to attach to. BSA/BSBA-FM only need the year level their
     * fixture roster row actually uses. BSIT is deliberately NOT listed here
     * — see `bsitCurriculumSubjects()`, which covers every (year_level,
     * semester) combination the year-4 IT401 student's 7-term cohort walk
     * touches, with several subjects per combination (not just one), so
     * `test_a_fourth_year_student_has_seven_completed_terms_of_locked_grades()`
     * has enough grade rows to exceed its ">40" assertion.
     *
     * @var list<array{program: string, year_level: int, semester: string, subject_code: string}>
     */
    private const CURRICULUM_SUBJECTS = [
        ['program' => 'BSA', 'year_level' => 1, 'semester' => '1st', 'subject_code' => 'ACC101S'],
        ['program' => 'BSA', 'year_level' => 3, 'semester' => '1st', 'subject_code' => 'ACC301S'],
        ['program' => 'BSBA-FM', 'year_level' => 1, 'semester' => '1st', 'subject_code' => 'FM101S'],
        ['program' => 'BSBA-FM', 'year_level' => 2, 'semester' => '1st', 'subject_code' => 'FM201S'],
    ];

    /**
     * Six subjects per (year_level, semester) combination that BSIT's own
     * year-4 (entry 2023) cohort passes through across all seven
     * `AcademicTermSeeder` terms: both semesters at years 1-3, and only the
     * 1st semester at year 4 (the current, still-open term). Year levels
     * 1-3 also happen to be exactly what the year-2/year-3/"today" BSIT
     * cohorts need for their own shorter walks (see
     * `StudentRosterSeeder::seedSectionHistory()`'s docblock — cohorts never
     * collide on the same (term, computed year level) pair), so nothing
     * beyond this single generated set is required.
     *
     * @return list<array{program: string, year_level: int, semester: string, subject_code: string}>
     */
    private function bsitCurriculumSubjects(): array
    {
        $combinations = [
            ['year_level' => 1, 'semester' => '1st'],
            ['year_level' => 1, 'semester' => '2nd'],
            ['year_level' => 2, 'semester' => '1st'],
            ['year_level' => 2, 'semester' => '2nd'],
            ['year_level' => 3, 'semester' => '1st'],
            ['year_level' => 3, 'semester' => '2nd'],
            ['year_level' => 4, 'semester' => '1st'],
        ];

        $rows = [];
        foreach ($combinations as $combination) {
            $semesterTag = $combination['semester'] === '1st' ? 'A' : 'B';
            for ($n = 1; $n <= 6; $n++) {
                $rows[] = [
                    'program' => 'BSIT',
                    'year_level' => $combination['year_level'],
                    'semester' => $combination['semester'],
                    'subject_code' => "IT{$combination['year_level']}{$semesterTag}{$n}",
                ];
            }
        }

        return $rows;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $curricula = [];
        foreach (self::PROGRAMS as $definition) {
            $program = Program::create([
                'code' => $definition['code'],
                'name' => $definition['code'].' Program',
                'college' => $definition['college'],
                'status' => ProgramStatus::Active,
            ]);

            $curricula[$definition['code']] = Curriculum::create([
                'program_id' => $program->id,
                'name' => $definition['code'].' Curriculum 2024-2029',
                'effective_school_year' => '2024-2029',
                'effective_start_year' => 2024,
                'effective_end_year' => 2029,
                'status' => CurriculumStatus::Active,
            ]);
        }

        $this->seed(AcademicTermSeeder::class);
        $this->seedSectionHistoryFixtures($curricula);

        User::create([
            'name' => 'Registrar Head',
            'email' => 'registrar.head@grc.test',
            'password' => 'password',
            'role' => UserRole::RegistrarHead,
            'status' => UserStatus::Active,
        ]);
    }

    /** @param array<string, Curriculum> $curricula */
    private function seedSectionHistoryFixtures(array $curricula): void
    {
        $curriculumSubjects = array_merge(self::CURRICULUM_SUBJECTS, $this->bsitCurriculumSubjects());

        foreach ($curriculumSubjects as $definition) {
            $college = self::PROGRAMS[$definition['program']]['college'];
            $subject = Subject::create([
                'code' => $definition['subject_code'],
                'college' => $college,
                'title' => $definition['subject_code'].' title',
                'units' => 3,
                'status' => SubjectStatus::Active,
            ]);
            CurriculumSubject::create([
                'curriculum_id' => $curricula[$definition['program']]->id,
                'subject_id' => $subject->id,
                'year_level' => $definition['year_level'],
                'semester' => $definition['semester'],
                'is_required' => true,
            ]);
        }

        // Several faculty members per college — not just one — so the
        // professor-assignment fallback chain ("any faculty in that
        // college") has enough candidates to avoid exhausting all
        // `SLOT_COUNT` (24) day/time slots now that historical blocks scale
        // with cohort headcount (Task 2 fix brief item 1): CCS/BSIT's own
        // current term alone needs professors for 31 blocks (9 + 8 + 7 + 7
        // across its four cohorts), and Task 3's `bsitCurriculumSubjects()`
        // multiplies that by 6 subjects per block (up to ~186 sections all
        // competing for the same term/day/time booking space, since
        // `pickProfessor()`'s slot-booking key is not subject-scoped) — 20
        // candidates x 24 slots = 480 comfortably covers that worst case,
        // where 6 never could.
        $collegeValues = array_unique(array_map(
            static fn (array $definition): string => $definition['college']->value,
            self::PROGRAMS,
        ));
        foreach ($collegeValues as $collegeValue) {
            $college = CollegeCode::from($collegeValue);
            for ($i = 1; $i <= 20; $i++) {
                User::create([
                    'name' => "Faculty {$college->value} {$i}",
                    'email' => "faculty.{$college->value}.{$i}@grc.test",
                    'password' => 'password',
                    'role' => UserRole::Faculty,
                    'college' => $college,
                    'status' => UserStatus::Active,
                ]);
            }
            RoomCatalogEntry::create([
                'name' => 'Room '.strtoupper($college->value).'-1',
                'college' => $college,
                'capacity' => 40,
                'room_type' => 'lecture',
            ]);
        }
    }

    public function test_it_creates_accounts_from_the_roster_file(): void
    {
        $this->publishStandardBlockPlaceholders();
        (new StudentRosterSeeder($this->fixturePath()))->run();
        $this->publishReachedBacklogSections();
        $this->reclassifyCurrentTermInPlace();

        $this->assertDatabaseHas('users', ['email' => 's2401455@grc.test', 'role' => 'student', 'status' => 'active']);
        $this->assertDatabaseHas('student_profiles', [
            'student_number' => '2024-06-01455', 'year_level' => 3, 'entry_year' => 2024, 'enrollment_category' => 'regular',
        ]);

        $user = User::query()->where('email', 's2401455@grc.test')->sole();
        $this->assertTrue(Hash::check('password', $user->password));

        $profile = StudentProfile::query()->where('student_number', '2024-06-01455')->sole();
        $program = Program::query()->where('code', 'BSA')->sole();
        $curriculum = Curriculum::query()->where('program_id', $program->id)->sole();
        $this->assertSame($program->id, $profile->program_id);
        $this->assertSame($curriculum->id, $profile->curriculum_id);
        $this->assertSame(AdmissionStatus::Admitted, $profile->admission_status);
        $this->assertSame(AcademicStanding::Good, $profile->academic_standing);
        $this->assertSame(UserRole::Student, $user->role);
        $this->assertSame(UserStatus::Active, $user->status);
    }

    public function test_it_assigns_the_fourth_year_roster_cohort_to_its_entry_year_curriculum(): void
    {
        $program = Program::query()->where('code', 'BSIT')->sole();
        $oldCurriculum = Curriculum::create([
            'program_id' => $program->id,
            'name' => 'BSIT Curriculum 2018-2023',
            'effective_school_year' => '2018-2023',
            'effective_start_year' => 2018,
            'effective_end_year' => 2023,
            'status' => CurriculumStatus::Archived,
        ]);

        (new StudentRosterSeeder($this->fixturePath()))->run();

        $profile = StudentProfile::query()->where('student_number', '2023-06-01455')->sole();
        $this->assertSame(2023, $profile->entry_year);
        $this->assertSame(4, $profile->year_level);
        $this->assertSame($oldCurriculum->id, $profile->curriculum_id);
    }

    public function test_running_twice_does_not_duplicate_accounts(): void
    {
        (new StudentRosterSeeder($this->fixturePath()))->run();
        $before = User::where('role', 'student')->count();
        (new StudentRosterSeeder($this->fixturePath()))->run();

        $this->assertSame($before, User::where('role', 'student')->count());
        $this->assertSame($before, StudentProfile::query()->count());
    }

    public function test_it_builds_a_section_history_that_walks_each_cohort_backwards(): void
    {
        (new StudentRosterSeeder($this->fixturePath()))->run();

        $current = AcademicTerm::where('school_year', '2026-2027')->where('semester', '1st')->sole();
        $earliest = AcademicTerm::where('school_year', '2023-2024')->where('semester', '1st')->sole();

        // a 4th-year student's block exists in the current term at year 4
        $this->assertDatabaseHas('sections', ['academic_term_id' => $current->id, 'section_code' => 'IT401']);
        // and in the earliest term at year 1 — the year-4 cohort's own
        // freshman year, scaled to its real headcount (StudentRosterMap's
        // CCS/BSIT year-4 cohort is 7 x 30-seat blocks = 210 students, so
        // ceil(210 / 40) = 6 blocks: IT101-IT106)
        $this->assertDatabaseHas('sections', ['academic_term_id' => $earliest->id, 'section_code' => 'IT101']);
        // a 7th block isn't needed for 210 students at 40 seats/block — this
        // is what proves the block count is computed from the cohort's real
        // headcount, not copied verbatim from today's real 7-block shape
        $this->assertDatabaseMissing('sections', ['academic_term_id' => $earliest->id, 'section_code' => 'IT107']);
    }

    public function test_historical_block_count_scales_with_cohort_headcount(): void
    {
        (new StudentRosterSeeder($this->fixturePath()))->run();

        $earliest = AcademicTerm::where('school_year', '2023-2024')->where('semester', '1st')->sole();

        // Only the CCS/BSIT year-4 cohort's own year-1 projection contributes
        // "IT10x"-coded sections to this term (every other BSIT cohort's own
        // entry year is later). Its real headcount (StudentRosterMap:
        // IT401-IT407, 7 x 30 = 210 students) must produce exactly
        // ceil(210 / 40) = 6 distinct blocks.
        $blockCount = Section::query()
            ->where('academic_term_id', $earliest->id)
            ->where('section_code', 'like', 'IT10%')
            ->distinct()
            ->count('section_code');

        $this->assertSame(6, $blockCount);
    }

    public function test_every_historical_section_has_a_professor(): void
    {
        (new StudentRosterSeeder($this->fixturePath()))->run();

        $this->assertSame(0, Section::whereNull('professor_id')->where('status', 'closed')->count());
    }

    public function test_no_professor_is_double_booked_in_the_same_term_day_and_time(): void
    {
        (new StudentRosterSeeder($this->fixturePath()))->run();

        $collisions = Section::query()
            ->where('status', 'closed')
            ->whereNotNull('professor_id')
            ->select(['academic_term_id', 'professor_id', 'schedule_days', 'starts_at_time'])
            ->groupBy('academic_term_id', 'professor_id', 'schedule_days', 'starts_at_time')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $this->assertCount(0, $collisions);
    }

    public function test_no_subjects_in_a_generated_block_overlap_for_a_student(): void
    {
        (new StudentRosterSeeder($this->fixturePath()))->run();

        $detector = app(SectionConflictDetector::class);

        Section::query()
            ->where('status', 'closed')
            ->get()
            ->groupBy(fn (Section $section): string => $section->academic_term_id.'|'.$section->section_code)
            ->each(function ($blockSections, string $blockKey) use ($detector): void {
                foreach ($blockSections->values() as $index => $section) {
                    $otherSlots = $blockSections
                        ->reject(fn (Section $other): bool => $other->is($section))
                        ->map(fn (Section $other): array => [
                            'schedule_days' => $other->schedule_days,
                            'starts_at_time' => $other->starts_at_time,
                            'ends_at_time' => $other->ends_at_time,
                        ])
                        ->values()
                        ->all();

                    self::assertFalse(
                        $detector->hasConflict([
                            'schedule_days' => $section->schedule_days,
                            'starts_at_time' => $section->starts_at_time,
                            'ends_at_time' => $section->ends_at_time,
                        ], $otherSlots),
                        "Generated block {$blockKey} has a timetable conflict at section {$section->id} (position {$index}).",
                    );
                }
            });
    }

    public function test_it_throws_when_a_college_has_no_eligible_professor(): void
    {
        // The fixture seeds exactly one Faculty user per used college.
        // Removing CCS's leaves BSIT's sections with zero eligible
        // candidates across all three fallback tiers.
        User::where('college', CollegeCode::Ccs)->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/no eligible professor for college 'ccs'/");

        (new StudentRosterSeeder($this->fixturePath()))->run();
    }

    public function test_tier_two_preference_fallback_is_scoped_to_the_sections_college(): void
    {
        $curriculum = Curriculum::query()->whereHas('program', fn ($query) => $query->where('code', 'BSIT'))->sole();
        $subject = Subject::query()->where('code', 'IT1A1')->sole();

        // A faculty preference row for a professor in a DIFFERENT college
        // than BSIT's (CCS), ranked first — if tier 2 isn't college-scoped,
        // this outsider would be preferred over CCS's own faculty for every
        // IT1A1 section.
        $outsider = User::create([
            'name' => 'Outsider Professor',
            'email' => 'outsider@grc.test',
            'password' => 'password',
            'role' => UserRole::Faculty,
            'college' => CollegeCode::Coa,
            'status' => UserStatus::Active,
        ]);

        DB::table('faculty_curriculum_subject_preferences')->insert([
            'professor_id' => $outsider->id,
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'semester' => '1st',
            'rank' => 1,
            'origin' => 'declared',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new StudentRosterSeeder($this->fixturePath()))->run();

        $this->assertSame(
            0,
            Section::query()->where('subject_id', $subject->id)->where('professor_id', $outsider->id)->count(),
        );
    }

    public function test_plan_rows_are_not_written_when_no_curriculum_subjects_match(): void
    {
        (new StudentRosterSeeder($this->fixturePath()))->run();

        $curriculum = Curriculum::query()->whereHas('program', fn ($query) => $query->where('code', 'BSA'))->sole();
        $current = AcademicTerm::where('school_year', '2026-2027')->where('semester', '1st')->sole();

        // The fixture's BSA curriculum only registers subjects at year
        // levels 1 and 3 (see CURRICULUM_SUBJECTS above), but StudentRosterMap
        // has real BSA cohorts at every year level 1-4 — the year-2/year-4
        // cohort-terms must not get a plan row with nothing behind it.
        $this->assertDatabaseMissing('academic_term_section_plans', [
            'academic_term_id' => $current->id, 'curriculum_id' => $curriculum->id, 'year_level' => 2,
        ]);
        $this->assertDatabaseMissing('academic_term_section_plans', [
            'academic_term_id' => $current->id, 'curriculum_id' => $curriculum->id, 'year_level' => 4,
        ]);

        // and every plan row that DOES exist has a section_count matching the
        // real number of distinct blocks generated under it — never overstated.
        AcademicTermSectionPlan::query()->get()->each(function (AcademicTermSectionPlan $plan): void {
            $actualBlocks = Section::query()->where('section_plan_id', $plan->id)->distinct()->count('section_code');
            $this->assertSame($actualBlocks, $plan->section_count, "plan {$plan->id} section_count mismatch");
        });
    }

    public function test_running_the_section_history_twice_does_not_duplicate_sections(): void
    {
        (new StudentRosterSeeder($this->fixturePath()))->run();
        $before = Section::query()->count();
        (new StudentRosterSeeder($this->fixturePath()))->run();

        $this->assertSame($before, Section::query()->count());
    }

    /**
     * The exact bug the final review caught: `orderedTerms()` used to treat
     * every `academic_terms` row as either "today" or a completed
     * historical term, with no status filter. If a Registrar Head opens a
     * new term (the ordinary archive-and-open workflow) and this seeder is
     * then rerun against that non-fresh database, the live
     * `semester_ongoing` term must never be populated with fabricated
     * `enrolled` enrollments or `locked` academic_grades.
     */
    public function test_seeding_skips_a_semester_ongoing_term(): void
    {
        $ongoingTerm = AcademicTerm::create([
            'school_year' => '2026-2027',
            'semester' => '2nd',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);

        (new StudentRosterSeeder($this->fixturePath()))->run();

        $this->assertSame(0, Section::where('academic_term_id', $ongoingTerm->id)->count());
        $this->assertSame(0, AcademicTermSectionPlan::where('academic_term_id', $ongoingTerm->id)->count());
        $this->assertSame(0, Enrollment::where('academic_term_id', $ongoingTerm->id)->count());
        $this->assertSame(0, AcademicGrade::where('academic_term_id', $ongoingTerm->id)->count());

        // Every other (completed) term is still seeded normally — this
        // isn't just "the ongoing term is empty", the rest of history is
        // unaffected by the filter.
        $this->assertGreaterThan(0, Section::query()->count());
        $this->assertGreaterThan(0, Enrollment::query()->count());
    }

    public function test_a_fourth_year_student_has_seven_completed_terms_of_locked_grades(): void
    {
        (new StudentRosterSeeder($this->fixturePath()))->run();

        $student = StudentProfile::where('student_number', '2023-06-01455')->sole();

        $this->assertSame(7, Enrollment::where('student_id', $student->id)->count());
        $this->assertSame(7, Enrollment::where('student_id', $student->id)->where('status', 'enrolled')->count());
        $this->assertGreaterThan(40, AcademicGrade::where('student_id', $student->id)->where('status', 'locked')->count());
    }

    public function test_section_enrolled_counts_match_the_enrollment_subject_rows(): void
    {
        (new StudentRosterSeeder($this->fixturePath()))->run();

        foreach (Section::where('status', 'closed')->get() as $section) {
            $this->assertSame(
                EnrollmentSubject::where('section_id', $section->id)->where('status', '!=', 'dropped')->count(),
                $section->enrolled_count,
            );
        }
    }

    public function test_running_the_enrollment_history_twice_does_not_duplicate_rows(): void
    {
        (new StudentRosterSeeder($this->fixturePath()))->run();
        $enrollmentsBefore = Enrollment::query()->count();
        $enrollmentSubjectsBefore = EnrollmentSubject::query()->count();
        $gradesBefore = AcademicGrade::query()->count();

        (new StudentRosterSeeder($this->fixturePath()))->run();

        $this->assertSame($enrollmentsBefore, Enrollment::query()->count());
        $this->assertSame($enrollmentSubjectsBefore, EnrollmentSubject::query()->count());
        $this->assertSame($gradesBefore, AcademicGrade::query()->count());
    }

    public function test_roughly_a_tenth_of_students_are_derived_as_irregular(): void
    {
        $this->publishStandardBlockPlaceholders();
        (new StudentRosterSeeder($this->irregularFixturePath()))->run();
        $this->publishReachedBacklogSections();
        $this->reclassifyAllStudents();

        $total = StudentProfile::count();
        $irregular = StudentProfile::where('enrollment_category', 'irregular')->count();

        $this->assertGreaterThan((int) ($total * 0.07), $irregular);
        $this->assertLessThan((int) ($total * 0.13), $irregular);
    }

    public function test_no_first_year_student_is_irregular(): void
    {
        $this->publishStandardBlockPlaceholders();
        (new StudentRosterSeeder($this->irregularFixturePath()))->run();
        $this->publishReachedBacklogSections();
        $this->reclassifyAllStudents();

        $this->assertSame(0, StudentProfile::where('year_level', 1)->where('enrollment_category', 'irregular')->count());
    }

    /**
     * Rewritten for the term-scoped rule (Task 5): "irregular" no longer
     * means "has some locked failing grade anywhere in history" — a student
     * can be Irregular purely from an unmet prerequisite with no such mark
     * at all, or stay Regular despite carrying one if the affected subject
     * isn't offered this term. Asserting directly against
     * `ClassifyEnrollmentStanding`'s own `reasons` (rather than
     * re-deriving the grade-mark check by hand) keeps this test from
     * silently drifting out of sync with the real rule again.
     */
    public function test_every_irregular_student_has_grade_evidence(): void
    {
        $this->publishStandardBlockPlaceholders();
        (new StudentRosterSeeder($this->irregularFixturePath()))->run();
        $this->publishReachedBacklogSections();
        $this->reclassifyAllStudents();

        $currentTerm = AcademicTerm::where('school_year', '2026-2027')->where('semester', '1st')->sole();
        $classifier = app(ClassifyEnrollmentStanding::class);

        $irregularStudents = StudentProfile::where('enrollment_category', 'irregular')->get();
        $this->assertNotEmpty($irregularStudents);

        foreach ($irregularStudents as $student) {
            $verdict = $classifier->classify($student, $currentTerm);

            $this->assertNotNull($verdict, "Student {$student->student_number} has no determinate standing.");
            $this->assertFalse($verdict->isRegular());
            $this->assertNotEmpty($verdict->reasons, "Student {$student->student_number} is irregular with no reasons.");

            foreach ($verdict->reasons as $reason) {
                $this->assertContains(
                    $reason['code'],
                    ['needs_adding_backlog', 'needs_removing_completed', 'needs_removing_prerequisite'],
                    "Student {$student->student_number} carries an unrecognized reason code '{$reason['code']}'.",
                );
            }
        }
    }

    /**
     * The exact bug the final review caught: `selectIrregularCandidates()`
     * used to stride across every `student_profiles` row by `id` with no
     * scope to the roster THIS seeder parsed. In the real `DatabaseSeeder`
     * chain, `DemoEnrollmentSeeder` runs first and its 10 demo profiles
     * therefore occupy the lowest ids — landing one of them (a documented-
     * Regular account) on the stride's very first boundary and silently
     * converting it to Irregular. This reproduces that shape directly: a
     * profile created BEFORE `run()` (so it gets the lowest id, same as the
     * real ordering), sharing the roster's own program, at an eligible year
     * level, but with a student number the fixture roster never mentions.
     */
    public function test_irregular_selection_never_reaches_a_student_outside_the_parsed_roster(): void
    {
        $program = Program::query()->where('code', 'BSA')->sole();
        $curriculum = Curriculum::query()->where('program_id', $program->id)->sole();
        $subject = Subject::query()->where('code', 'ACC301S')->sole();
        $term = AcademicTerm::query()->orderBy('id')->firstOrFail();
        $encoder = User::query()->where('role', UserRole::Faculty)->firstOrFail();

        $outsideUser = User::create([
            'name' => 'Seed Student Two',
            'email' => 'student2.seed@grc.test',
            'password' => 'password',
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
        ]);
        $outsideProfile = StudentProfile::create([
            'user_id' => $outsideUser->id,
            'student_number' => '2023-06-00002',
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'entry_year' => 2023,
            'year_level' => 2,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);

        // Pre-existing clean locked grade evidence, exactly like
        // `DemoEnrollmentSeeder` leaves behind for its own documented-
        // Regular accounts.
        AcademicGrade::create([
            'student_id' => $outsideProfile->id,
            'subject_id' => $subject->id,
            'academic_term_id' => $term->id,
            'final_grade' => '1.00',
            'mark' => '1.00',
            'status' => 'locked',
            'encoded_by' => $encoder->id,
            'submitted_at' => now(),
            'locked_at' => now(),
        ]);

        $this->publishStandardBlockPlaceholders();
        (new StudentRosterSeeder($this->irregularFixturePath()))->run();
        $this->publishReachedBacklogSections();
        $this->reclassifyAllStudents();

        $this->assertDatabaseHas('academic_grades', [
            'student_id' => $outsideProfile->id,
            'subject_id' => $subject->id,
            'academic_term_id' => $term->id,
            'mark' => '1.00',
        ]);

        $outsideProfile->refresh();
        $this->assertSame('regular', $outsideProfile->enrollment_category);
    }

    /**
     * Pins the Task 3/4 crosscut invariant directly: `gradeMarkFor()`'s
     * baseline is clean/passing-only, so the ONLY `academic_grades` rows
     * that may ever carry a blocking mark are the ones
     * `rewriteGradesToFailing()` deliberately wrote for
     * `selectIrregularCandidates()`'s own selected ~10% cohort. Every other
     * student — regardless of year level, program, or how many graded
     * subject-instances they accumulate — must have zero blocking marks.
     * Deliberately independent of the sparse two-subject curriculum
     * fixture's density: this counts real rows across the whole roster
     * rather than asserting against any one student, so a reintroduced
     * small failure-chance in the baseline would show up here even if it
     * happened to miss every individually-asserted student.
     */
    public function test_only_the_selected_irregular_cohort_ever_has_a_failing_mark(): void
    {
        (new StudentRosterSeeder($this->irregularFixturePath()))->run();

        // Mirrors `selectIrregularCandidates()`'s own documented,
        // deterministic stride (every 10th eligible year 2-4 student, in
        // stable id order) so this test pins the invariant without
        // reaching into the seeder's private internals.
        $eligible = StudentProfile::query()
            ->where('year_level', '>=', 2)
            ->orderBy('id')
            ->pluck('id')
            ->values();

        // Mirrors `StudentRosterSeeder::IRREGULAR_SELECTION_STRIDE` (10) —
        // that constant is private, so the value is duplicated here rather
        // than reached into.
        $stride = 10;
        $selectedIds = [];
        foreach ($eligible as $index => $id) {
            if ($index % $stride === 0) {
                $selectedIds[] = $id;
            }
        }

        $this->assertNotEmpty($selectedIds, 'fixture must produce at least one selected irregular candidate');

        $baselineOffenders = AcademicGrade::query()
            ->whereNotIn('student_id', $selectedIds)
            ->whereIn('mark', ['5.00', 'INC', 'NC', 'DRP'])
            ->count();

        $this->assertSame(0, $baselineOffenders, 'a student outside the selected irregular cohort has a blocking mark');
    }

    public function test_running_the_seeder_twice_does_not_change_the_derived_categories(): void
    {
        $this->publishStandardBlockPlaceholders();
        (new StudentRosterSeeder($this->irregularFixturePath()))->run();
        (new StudentRosterSeeder($this->irregularFixturePath()))->run();
        $this->publishReachedBacklogSections();
        $this->reclassifyAllStudents();

        $before = StudentProfile::query()->orderBy('id')->pluck('enrollment_category', 'id');

        (new StudentRosterSeeder($this->irregularFixturePath()))->run();
        $this->reclassifyAllStudents();

        $after = StudentProfile::query()->orderBy('id')->pluck('enrollment_category', 'id');

        $this->assertSame($before->all(), $after->all());
    }

    public function test_fresh_seed_derives_categories_and_includes_ccs_third_year_irregular_students(): void
    {
        self::assertSame(0, AcademicTerm::where('status', AcademicTermStatus::SemesterOngoing)->count());
        self::assertSame(1, AcademicTerm::where('status', AcademicTermStatus::SemesterClosed)->count());

        $this->publishStandardBlockPlaceholders();
        (new StudentRosterSeeder($this->ccsThirdYearFixturePath()))->run();
        $this->publishReachedBacklogSections();
        $this->reclassifyCurrentTermInPlace();

        $this->assertSame(0, StudentProfile::whereNull('enrollment_category')->count());
        $this->assertGreaterThan(0, StudentProfile::where('enrollment_category', 'regular')->count());

        $ccsThirdYearIrregular = StudentProfile::query()
            ->where('year_level', 3)
            ->where('enrollment_category', 'irregular')
            ->whereHas('program', fn ($query) => $query->where('college', CollegeCode::Ccs))
            ->get();

        $this->assertNotEmpty($ccsThirdYearIrregular);

        foreach ($ccsThirdYearIrregular as $student) {
            $this->assertTrue(AcademicGrade::where('student_id', $student->id)
                ->whereIn('mark', ['5.00', 'INC', 'NC', 'DRP'])
                ->exists());
        }
    }

    public function test_fresh_seed_classifies_a_roster_with_no_irregular_eligible_students(): void
    {
        $termStatuses = AcademicTerm::query()->orderBy('id')->pluck('status', 'id');

        $this->publishStandardBlockPlaceholders();
        (new StudentRosterSeeder($this->firstYearFixturePath()))->run();
        $this->publishReachedBacklogSections();
        $this->reclassifyCurrentTermInPlace();

        $this->assertSame(0, StudentProfile::whereNull('enrollment_category')->count());
        $this->assertSame(1, StudentProfile::where('enrollment_category', 'regular')->count());
        $this->assertSame(
            $termStatuses->all(),
            AcademicTerm::query()->orderBy('id')->pluck('status', 'id')->all(),
        );
    }

    /**
     * Mirrors the explicit `php artisan students:reclassify` workflow after
     * a Registrar Head opens a `semester_ongoing` term. The seeder already
     * derives fresh categories against the current slot; tests that exercise
     * the later ongoing-term context invoke that same action again here.
     */
    private function reclassifyAllStudents(): void
    {
        $currentTerm = AcademicTerm::where('school_year', '2026-2027')->where('semester', '1st')->sole();
        $currentTerm->update(['status' => AcademicTermStatus::SemesterOngoing]);

        $registrarHead = User::query()->where('role', UserRole::RegistrarHead)->first()
            ?? User::create([
                'name' => 'Registrar Head',
                'email' => 'registrar.head@grc.test',
                'password' => 'password',
                'role' => UserRole::RegistrarHead,
                'status' => UserStatus::Active,
            ]);

        app(ReclassifyStudentEnrollmentCategory::class)->executeMany(
            StudentProfile::query()->get(),
            $currentTerm,
            $registrarHead,
            new AuditRequestContext('student-roster-seeder-test', null),
        );
    }

    /**
     * Publishes the `Published`/`is_block_exclusive` "standard block"
     * section `ClassifyEnrollmentStanding` (Task 2) needs to consider a
     * student's standing determinate at all — `StudentRosterSeeder`'s own
     * generated sections are always `SectionStatus::Closed` (real
     * completed-term history, per its own class docblock); in real
     * production the current term's live, enrollable blocks come from a
     * separate seeder (`ProgramChairScheduleSampleSeeder`), never from
     * `StudentRosterSeeder` itself. Must run BEFORE `StudentRosterSeeder::run()`
     * so its own internal `reclassifyAgainstCurrentTerm()` call already
     * finds it in place.
     *
     * One throwaway subject per (program, year level 1-4) — deliberately
     * NEVER registered as a `CurriculumSubject` placement, so
     * `ClassifyEnrollmentStanding` silently skips it in its own
     * completion/prerequisite loop (`$placements->get($id) === null`
     * there). Its only job is to make `standardSubjectIds` non-empty. This
     * can't reuse one of this file's REAL registered subjects instead:
     * those sit at exactly a cohort's own (year_level, '1st') slot, which
     * `seedSectionHistory()` also treats as "today" and therefore already
     * grades as history — reusing one as this term's "still to take" block
     * would make every already-passing student wrongly trip
     * `needs_removing_completed`.
     */
    private function publishStandardBlockPlaceholders(): void
    {
        $current = AcademicTerm::where('school_year', '2026-2027')->where('semester', '1st')->sole();

        foreach (self::PROGRAMS as $programCode => $definition) {
            $program = Program::query()->where('code', $programCode)->sole();
            $curriculum = Curriculum::query()->where('program_id', $program->id)->sole();

            $dummySubject = Subject::firstOrCreate(
                ['code' => 'ZZDUMMY-'.$programCode, 'college' => $definition['college']],
                ['title' => 'Classification placeholder', 'units' => 3, 'status' => SubjectStatus::Active],
            );

            for ($yearLevel = 1; $yearLevel <= 4; $yearLevel++) {
                $plan = AcademicTermSectionPlan::create([
                    'academic_term_id' => $current->id,
                    'curriculum_id' => $curriculum->id,
                    'college' => 'test-block',
                    'year_level' => $yearLevel,
                    'section_count' => 1,
                    'students_per_block' => 40,
                    'status' => 'submitted',
                ]);

                Section::create([
                    'academic_term_id' => $current->id,
                    'section_plan_id' => $plan->id,
                    'subject_id' => $dummySubject->id,
                    'section_code' => 'ZZ-BLOCK-'.$yearLevel,
                    'schedule_days' => 'Sun',
                    'starts_at_time' => '06:00:00',
                    'ends_at_time' => '07:00:00',
                    'capacity' => 40,
                    'enrolled_count' => 0,
                    'is_block_exclusive' => true,
                    'status' => SectionStatus::Published,
                ]);
            }
        }
    }

    /**
     * Publishes an open (non-block-exclusive) backlog section this term for
     * every subject that some student was ACTUALLY graded on by
     * `StudentRosterSeeder::run()` — so a candidate
     * `StudentRosterSeeder::seedIrregularStudents()` rewrote a failing mark
     * onto reads as `needs_adding_backlog` (Irregular), the same
     * `makePlainSection()` pattern `ClassifyEnrollmentStandingTest`/
     * `ReclassifyStudentEnrollmentCategoryTest` already established, while
     * every other (passing-mark) student on that same subject is already
     * completed and is skipped — never a false Irregular.
     *
     * Deliberately scoped to subjects with real grade evidence rather than
     * every subject `CURRICULUM_SUBJECTS`/`bsitCurriculumSubjects()`
     * registers: this fixture has no `SubjectPrerequisite` rows at all, so
     * nothing would otherwise stop a subject a cohort hasn't reached yet
     * (e.g. year-1-second-semester for a student still in their first
     * semester) from being wrongly read as an open, actionable, never-taken
     * backlog subject the instant it has a `Published` section. Must run
     * AFTER `StudentRosterSeeder::run()` has written its grade history, and
     * BEFORE whichever classification pass this test relies on next
     * (`reclassifyAllStudents()` or `reclassifyCurrentTermInPlace()`).
     */
    private function publishReachedBacklogSections(): void
    {
        $current = AcademicTerm::where('school_year', '2026-2027')->where('semester', '1st')->sole();
        $reachedSubjectIds = AcademicGrade::query()->distinct()->pluck('subject_id');

        foreach ($reachedSubjectIds as $subjectId) {
            Section::firstOrCreate(
                [
                    'academic_term_id' => $current->id,
                    'subject_id' => $subjectId,
                    'section_code' => 'ZZ-BACKLOG',
                ],
                [
                    'schedule_days' => 'Sun',
                    'starts_at_time' => '07:00:00',
                    'ends_at_time' => '08:00:00',
                    'capacity' => 999,
                    'enrolled_count' => 0,
                    'is_block_exclusive' => false,
                    'status' => SectionStatus::Published,
                ],
            );
        }
    }

    /**
     * Like `reclassifyAllStudents()`, but leaves the current term's own
     * status untouched — for tests that assert a fresh seed's term
     * lifecycle is undisturbed while still needing a classification pass
     * that sees sections `publishReachedBacklogSections()` only adds AFTER
     * `StudentRosterSeeder::run()`'s own internal
     * `reclassifyAgainstCurrentTerm()` call already ran once.
     */
    private function reclassifyCurrentTermInPlace(): void
    {
        $currentTerm = AcademicTerm::where('school_year', '2026-2027')->where('semester', '1st')->sole();

        $registrarHead = User::query()->where('role', UserRole::RegistrarHead)->firstOrFail();

        app(ReclassifyStudentEnrollmentCategory::class)->executeMany(
            StudentProfile::query()->get(),
            $currentTerm,
            $registrarHead,
            new AuditRequestContext('student-roster-seeder-test', null),
        );
    }

    private function fixturePath(): string
    {
        return __DIR__.'/../../fixtures/students-profile-sample.md';
    }

    private function ccsThirdYearFixturePath(): string
    {
        return __DIR__.'/../../fixtures/students-profile-ccs-third-year-sample.md';
    }

    private function firstYearFixturePath(): string
    {
        return __DIR__.'/../../fixtures/students-profile-first-year-sample.md';
    }

    /**
     * The ordinary 17-student `students-profile-sample.md` fixture is too
     * small for `test_roughly_a_tenth_of_students_are_derived_as_irregular()`'s
     * `(int) ($total * 0.07)` / `(int) ($total * 0.13)` window to ever admit
     * an achievable integer count (17 * 0.07 and 17 * 0.13 both truncate to
     * the same integer, leaving no room between them — true of most small
     * totals, not just 17).
     *
     * This 70-student fixture is a dedicated, larger roster built for this
     * one concern: 30 BSBA-FM year-2 (`FM201`) + 30 BSA year-3 (`ACC301`)
     * students as the eligible (year 2-4) population — deliberately kept on
     * the SAME sparse two-subject curriculum `students-profile-sample.md`
     * already registers, not BSIT's dense per-term one, to avoid piling up
     * more graded subject-instances than necessary. The remaining 10 rows
     * are BSIT year-1 (`IT101`) students — never eligible (year_level < 2),
     * so they never turn irregular, but give
     * `test_no_first_year_student_is_irregular()` a population to assert
     * zero against and pad `StudentProfile::count()` a little.
     *
     * Since the Task 3/4 crosscut fix (`gradeMarkFor()`'s baseline is now
     * clean/passing-only — see that method's docblock), the only irregular
     * students in this fixture are `StudentRosterSeeder::seedIrregularStudents()`'s
     * own deliberate `IRREGULAR_SELECTION_STRIDE`-based selection: every
     * 10th student among the 60 eligible (year 2-4), i.e. exactly 6
     * (indices 0, 10, 20, 30, 40, 50). 6 / 70 = 8.57%, which lands inside
     * `(0.07 * 70, 0.13 * 70) = (4, 9)` (using the same truncating `(int)`
     * cast the assertion uses) with real margin on both sides. Every other
     * test in this file intentionally keeps using the smaller shared
     * fixture.
     */
    private function irregularFixturePath(): string
    {
        return __DIR__.'/../../fixtures/students-profile-irregular-sample.md';
    }
}
