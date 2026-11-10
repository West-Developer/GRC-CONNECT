<?php

namespace Tests\Feature\Actions\Analytics;

use App\Actions\Analytics\DeriveSectionDemandObservations;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\Program;
use App\Models\RoomCatalogEntry;
use App\Models\Section;
use App\Models\SectionDemandObservation;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\AcademicTermSeeder;
use Database\Seeders\StudentRosterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mirrors `StudentRosterSeederTest`'s own fixture scaffolding (same three
 * programs, same `students-profile-sample.md` roster) since
 * `DeriveSectionDemandObservations` reads exactly the real
 * enrollment/section/grade history that seeder builds — there is no smaller
 * fixture that would exercise a real, multi-table aggregation query.
 */
final class DeriveSectionDemandObservationsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array{code: string, college: CollegeCode}> */
    private const PROGRAMS = [
        'BSBA-FM' => ['code' => 'BSBA-FM', 'college' => CollegeCode::Cbae],
        'BSA' => ['code' => 'BSA', 'college' => CollegeCode::Coa],
        'BSIT' => ['code' => 'BSIT', 'college' => CollegeCode::Ccs],
    ];

    /** @var list<array{program: string, year_level: int, semester: string, subject_code: string}> */
    private const CURRICULUM_SUBJECTS = [
        ['program' => 'BSA', 'year_level' => 1, 'semester' => '1st', 'subject_code' => 'ACC101S'],
        ['program' => 'BSA', 'year_level' => 3, 'semester' => '1st', 'subject_code' => 'ACC301S'],
        ['program' => 'BSBA-FM', 'year_level' => 1, 'semester' => '1st', 'subject_code' => 'FM101S'],
        ['program' => 'BSBA-FM', 'year_level' => 2, 'semester' => '1st', 'subject_code' => 'FM201S'],
    ];

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

        // StudentRosterSeeder attributes its enrollment_category audit rows to a Registrar Head
        // and refuses to run without one.
        User::create([
            'name' => 'Registrar Head',
            'email' => 'registrar.head.demand@grc.test',
            'password' => 'correct-horse-battery-staple',
            'role' => UserRole::RegistrarHead,
            'status' => UserStatus::Active,
        ]);

        foreach (self::CURRICULUM_SUBJECTS as $definition) {
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

    public function test_it_aggregates_real_enrollments_into_demand_observations(): void
    {
        (new StudentRosterSeeder($this->fixturePath()))->run();

        $count = app(DeriveSectionDemandObservations::class)->execute();

        $this->assertGreaterThan(0, $count);
        $observation = SectionDemandObservation::where('source', 'derived_from_enrollments')->first();
        $this->assertNotNull($observation);
        $this->assertSame(
            EnrollmentSubject::whereHas('section', fn ($q) => $q
                ->where('academic_term_id', $observation->academic_term_id)
                ->where('subject_id', $observation->subject_id))->count(),
            $observation->enrolled_count,
        );
    }

    /**
     * A synthetic row seeded at the exact key real BSA/ACC301S history
     * resolves to (program BSA, its curriculum, subject ACC301S, the
     * student's current year_level 3, and the one term that cohort's
     * `ACC301` block actually exists in) must be replaced by the derived
     * row, not left alone or duplicated. `updateOrCreate` is used (rather
     * than `create`) because `StudentRosterSeeder::run()` itself already
     * calls `DeriveSectionDemandObservations` as its own last step — by the
     * time this test seeds its synthetic row, a `derived_from_enrollments`
     * row already occupies this exact unique key, and this test is
     * deliberately overwriting it back to synthetic to prove the second,
     * explicit `execute()` call below restores the derived values.
     */
    public function test_it_overwrites_synthetic_observations_for_the_same_key(): void
    {
        (new StudentRosterSeeder($this->fixturePath()))->run();

        $program = Program::query()->where('code', 'BSA')->sole();
        $curriculum = Curriculum::query()->where('program_id', $program->id)->sole();
        $subject = Subject::query()->where('code', 'ACC301S')->sole();
        $termId = $this->termIdForStudentSubject('2024-06-01451', 'ACC301S');

        $key = [
            'academic_term_id' => $termId,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'year_level' => 3,
        ];

        SectionDemandObservation::updateOrCreate($key, [
            'college' => CollegeCode::Coa,
            'cohort_size' => 999,
            'enrolled_count' => 999,
            'section_count' => 999,
            'offered_capacity' => 999,
            'source' => 'local_synthetic_aggregate',
        ]);

        app(DeriveSectionDemandObservations::class)->execute();

        $observation = SectionDemandObservation::where($key)->sole();
        $this->assertSame('derived_from_enrollments', $observation->source);
        $this->assertNotSame(999, $observation->enrolled_count);
        $this->assertGreaterThan(0, $observation->enrolled_count);
    }

    /**
     * A synthetic row keyed to a (term, subject) pairing real history never
     * produces — the real BSA/year-3 student's ACC301S section lives in
     * their "today" term (elapsed 0 academic years), while ACC101S is their
     * OWN historical year-1 term (elapsed 2 academic years) — is untouched
     * fallback data, not an orphan to clean up. `execute()` only ever
     * upserts keys its own aggregation query actually produces.
     */
    public function test_it_does_not_touch_synthetic_observations_without_matching_real_data(): void
    {
        (new StudentRosterSeeder($this->fixturePath()))->run();

        $program = Program::query()->where('code', 'BSA')->sole();
        $curriculum = Curriculum::query()->where('program_id', $program->id)->sole();
        $acc301TermId = $this->termIdForStudentSubject('2024-06-01451', 'ACC301S');
        $acc101SubjectId = Subject::query()->where('code', 'ACC101S')->value('id');

        // ACC101S paired with the "today" term (where only ACC301S really
        // has a section) never has any real backing.
        $key = [
            'academic_term_id' => $acc301TermId,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'subject_id' => $acc101SubjectId,
            'year_level' => 3,
        ];

        SectionDemandObservation::updateOrCreate($key, [
            'college' => CollegeCode::Coa,
            'cohort_size' => 50,
            'enrolled_count' => 45,
            'section_count' => 2,
            'offered_capacity' => 80,
            'source' => 'local_synthetic_aggregate',
        ]);

        app(DeriveSectionDemandObservations::class)->execute();

        $observation = SectionDemandObservation::where($key)->sole();
        $this->assertSame('local_synthetic_aggregate', $observation->source);
        $this->assertSame(45, $observation->enrolled_count);
    }

    public function test_it_only_upserts_observations_for_the_given_term_when_one_is_passed(): void
    {
        (new StudentRosterSeeder($this->fixturePath()))->run();
        SectionDemandObservation::query()->delete();

        $acc301TermId = $this->termIdForStudentSubject('2024-06-01451', 'ACC301S');
        $acc101TermId = $this->termIdForStudentSubject('2024-06-01451', 'ACC101S');
        $this->assertNotSame($acc301TermId, $acc101TermId);

        $count = app(DeriveSectionDemandObservations::class)->execute(AcademicTerm::findOrFail($acc301TermId));

        $this->assertGreaterThan(0, $count);
        $this->assertSame(0, SectionDemandObservation::where('academic_term_id', $acc101TermId)->count());
        $this->assertGreaterThan(0, SectionDemandObservation::where('academic_term_id', $acc301TermId)->count());
    }

    public function test_running_it_twice_does_not_change_the_row_count(): void
    {
        (new StudentRosterSeeder($this->fixturePath()))->run();

        app(DeriveSectionDemandObservations::class)->execute();
        $before = SectionDemandObservation::where('source', 'derived_from_enrollments')->count();

        app(DeriveSectionDemandObservations::class)->execute();
        $after = SectionDemandObservation::where('source', 'derived_from_enrollments')->count();

        $this->assertSame($before, $after);
    }

    /**
     * The exact bug task review caught: a student's `student_profiles.year_level`
     * is their CURRENT standing (4 here), completely unrelated to what year
     * level the historical section they're enrolled in was actually planned
     * at (1 here, via `academic_term_section_plans.year_level`). The
     * resulting observation's `year_level` must be the plan's historical
     * value, never the student's current one — that's what
     * `GenerateSectionDemandForecasts` looks up by by (via
     * `HistoricalCohortResolver`), and using the student's current value
     * would make this derived row invisible to its own consumer for every
     * term except a student's most recent one.
     */
    public function test_year_level_comes_from_the_historical_section_plan_not_the_students_current_profile(): void
    {
        $program = Program::query()->where('code', 'BSA')->sole();
        $curriculum = Curriculum::query()->where('program_id', $program->id)->sole();
        $term = AcademicTerm::query()->orderBy('id')->firstOrFail();

        $subject = Subject::create([
            'code' => 'HISTYR1', 'college' => CollegeCode::Coa,
            'title' => 'Historical Year Level Probe', 'units' => 3, 'status' => SubjectStatus::Active,
        ]);

        $plan = AcademicTermSectionPlan::create([
            'academic_term_id' => $term->id,
            'curriculum_id' => $curriculum->id,
            'college' => CollegeCode::Coa->value,
            'year_level' => 1,
            'section_count' => 1,
            'status' => 'submitted',
        ]);

        $section = Section::create([
            'academic_term_id' => $term->id,
            'section_plan_id' => $plan->id,
            'subject_id' => $subject->id,
            'section_code' => 'HISTYR1A',
            'capacity' => 40,
            'capacity_source' => 'plan',
            'status' => 'closed',
        ]);

        $user = User::create(['name' => 'Senior Student', 'email' => 'senior.student@grc.test', 'password' => 'password', 'role' => 'student', 'status' => 'active']);
        $student = StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => '2020-06-09001',
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'entry_year' => 2020,
            // Deliberately far from the plan's year_level (1) above — this
            // value must never leak into the derived observation.
            'year_level' => 4,
            'admission_status' => 'admitted',
            'academic_standing' => 'good',
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_term_id' => $term->id,
            'status' => 'enrolled',
            'total_units' => 3,
        ]);

        EnrollmentSubject::create([
            'enrollment_id' => $enrollment->id,
            'section_id' => $section->id,
            'status' => 'enrolled',
        ]);

        app(DeriveSectionDemandObservations::class)->execute();

        $observation = SectionDemandObservation::where([
            'academic_term_id' => $term->id,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
        ])->sole();

        $this->assertSame(1, $observation->year_level);
        $this->assertNotSame($student->year_level, $observation->year_level);
    }

    /**
     * A fully hand-computable scenario, independent of `StudentRosterSeeder`,
     * closing the coverage gap task review flagged: only `enrolled_count` had
     * ever been asserted against real computed output before this test —
     * `cohort_size`, `section_count`, and `offered_capacity` were only ever
     * used as fixture-seeding values in the overwrite tests above, never
     * verified as the Action's own output.
     *
     * Cohort (program BSA, plan year_level 2, this term): 4 distinct
     * students total — 3 took Subject A (across two sections, so a
     * duplicate-section student doesn't double count), 1 took Subject B.
     *   Subject A: enrolled_count=3 (distinct students, not row count),
     *              section_count=2, offered_capacity=40+35=75, cohort_size=4.
     *   Subject B: enrolled_count=1, section_count=1, offered_capacity=40,
     *              cohort_size=4 (same cohort denominator as Subject A —
     *              cohort_size counts every student at this program/year
     *              level that term, regardless of which subject they took).
     */
    public function test_it_computes_cohort_size_section_count_and_offered_capacity_from_real_data(): void
    {
        $program = Program::query()->where('code', 'BSA')->sole();
        $curriculum = Curriculum::query()->where('program_id', $program->id)->sole();
        $term = AcademicTerm::query()->orderBy('id')->firstOrFail();

        $subjectA = Subject::create(['code' => 'CNT101', 'college' => CollegeCode::Coa, 'title' => 'Count Probe A', 'units' => 3, 'status' => SubjectStatus::Active]);
        $subjectB = Subject::create(['code' => 'CNT102', 'college' => CollegeCode::Coa, 'title' => 'Count Probe B', 'units' => 3, 'status' => SubjectStatus::Active]);

        $plan = AcademicTermSectionPlan::create([
            'academic_term_id' => $term->id,
            'curriculum_id' => $curriculum->id,
            'college' => CollegeCode::Coa->value,
            'year_level' => 2,
            'section_count' => 3,
            'status' => 'submitted',
        ]);

        $sectionA1 = Section::create(['academic_term_id' => $term->id, 'section_plan_id' => $plan->id, 'subject_id' => $subjectA->id, 'section_code' => 'CNT101A', 'capacity' => 40, 'capacity_source' => 'plan', 'status' => 'closed']);
        $sectionA2 = Section::create(['academic_term_id' => $term->id, 'section_plan_id' => $plan->id, 'subject_id' => $subjectA->id, 'section_code' => 'CNT101B', 'capacity' => 35, 'capacity_source' => 'plan', 'status' => 'closed']);
        $sectionB1 = Section::create(['academic_term_id' => $term->id, 'section_plan_id' => $plan->id, 'subject_id' => $subjectB->id, 'section_code' => 'CNT102A', 'capacity' => 40, 'capacity_source' => 'plan', 'status' => 'closed']);

        // student1 -> Subject A / sectionA1, student2 -> Subject A / sectionA2,
        // student3 -> Subject A / sectionA1 (same section as student1, proving
        // enrolled_count/section_count dedupe correctly), student4 -> Subject B.
        $assignments = [
            ['number' => '2021-06-00001', 'section' => $sectionA1],
            ['number' => '2021-06-00002', 'section' => $sectionA2],
            ['number' => '2021-06-00003', 'section' => $sectionA1],
            ['number' => '2021-06-00004', 'section' => $sectionB1],
        ];

        foreach ($assignments as $assignment) {
            $user = User::create(['name' => 'Count Probe '.$assignment['number'], 'email' => $assignment['number'].'@grc.test', 'password' => 'password', 'role' => 'student', 'status' => 'active']);
            $student = StudentProfile::create([
                'user_id' => $user->id,
                'student_number' => $assignment['number'],
                'program_id' => $program->id,
                'curriculum_id' => $curriculum->id,
                'entry_year' => 2021,
                'year_level' => 2,
                'admission_status' => 'admitted',
                'academic_standing' => 'good',
            ]);
            $enrollment = Enrollment::create([
                'student_id' => $student->id,
                'academic_term_id' => $term->id,
                'status' => 'enrolled',
                'total_units' => 3,
            ]);
            EnrollmentSubject::create([
                'enrollment_id' => $enrollment->id,
                'section_id' => $assignment['section']->id,
                'status' => 'enrolled',
            ]);
        }

        app(DeriveSectionDemandObservations::class)->execute();

        $key = ['academic_term_id' => $term->id, 'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'year_level' => 2];

        $observationA = SectionDemandObservation::where($key + ['subject_id' => $subjectA->id])->sole();
        $this->assertSame(3, $observationA->enrolled_count);
        $this->assertSame(2, $observationA->section_count);
        $this->assertSame(75, $observationA->offered_capacity);
        $this->assertSame(4, $observationA->cohort_size);

        $observationB = SectionDemandObservation::where($key + ['subject_id' => $subjectB->id])->sole();
        $this->assertSame(1, $observationB->enrolled_count);
        $this->assertSame(1, $observationB->section_count);
        $this->assertSame(40, $observationB->offered_capacity);
        $this->assertSame(4, $observationB->cohort_size);
    }

    /**
     * The academic_term_id of the real section a specific real fixture
     * student (by student_number) actually took a specific subject (by
     * code) in — unambiguous even though the same subject code can have
     * sections in more than one term across different cohorts (e.g. every
     * BSA cohort walking through a projected year level 3 in semester 1
     * gets its own ACC301S section), because this resolves through the
     * student's own `enrollment_subjects` row rather than guessing at the
     * `sections` table directly.
     */
    /**
     * The exact bug the final review caught: the section-level query used to
     * drive off `enrollment_subjects`, so a section with zero non-dropped
     * enrollments contributed to neither `section_count` nor
     * `offered_capacity` — making `offered_capacity` mean "capacity of
     * sections that had at least one student" instead of "capacity actually
     * offered", the wrong denominator for utilization-based forecasting.
     *
     * Cohort (program BSA, plan year_level 2, this term): Subject A has two
     * sections — one with a real enrolled student, one entirely empty — and
     * Subject B has one section with zero enrollments at all. Both empty
     * sections must still be counted and contribute their capacity, while
     * contributing nothing to `enrolled_count`.
     */
    public function test_a_section_with_zero_enrollments_still_contributes_to_section_count_and_capacity(): void
    {
        $program = Program::query()->where('code', 'BSA')->sole();
        $curriculum = Curriculum::query()->where('program_id', $program->id)->sole();
        $term = AcademicTerm::query()->orderBy('id')->firstOrFail();

        $subjectA = Subject::create(['code' => 'ZERO101', 'college' => CollegeCode::Coa, 'title' => 'Zero-Enrollment Probe A', 'units' => 3, 'status' => SubjectStatus::Active]);
        $subjectB = Subject::create(['code' => 'ZERO102', 'college' => CollegeCode::Coa, 'title' => 'Zero-Enrollment Probe B', 'units' => 3, 'status' => SubjectStatus::Active]);

        $plan = AcademicTermSectionPlan::create([
            'academic_term_id' => $term->id,
            'curriculum_id' => $curriculum->id,
            'college' => CollegeCode::Coa->value,
            'year_level' => 2,
            'section_count' => 3,
            'status' => 'submitted',
        ]);

        $sectionA1 = Section::create(['academic_term_id' => $term->id, 'section_plan_id' => $plan->id, 'subject_id' => $subjectA->id, 'section_code' => 'ZERO101A', 'capacity' => 40, 'capacity_source' => 'plan', 'status' => 'closed']);
        Section::create(['academic_term_id' => $term->id, 'section_plan_id' => $plan->id, 'subject_id' => $subjectA->id, 'section_code' => 'ZERO101B', 'capacity' => 30, 'capacity_source' => 'plan', 'status' => 'closed']);
        Section::create(['academic_term_id' => $term->id, 'section_plan_id' => $plan->id, 'subject_id' => $subjectB->id, 'section_code' => 'ZERO102A', 'capacity' => 25, 'capacity_source' => 'plan', 'status' => 'closed']);

        $user = User::create(['name' => 'Zero Probe Student', 'email' => 'zero.probe@grc.test', 'password' => 'password', 'role' => 'student', 'status' => 'active']);
        $student = StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => '2022-06-00001',
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'entry_year' => 2022,
            'year_level' => 2,
            'admission_status' => 'admitted',
            'academic_standing' => 'good',
        ]);
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_term_id' => $term->id,
            'status' => 'enrolled',
            'total_units' => 3,
        ]);
        EnrollmentSubject::create([
            'enrollment_id' => $enrollment->id,
            'section_id' => $sectionA1->id,
            'status' => 'enrolled',
        ]);
        // sectionA2 and Subject B's only section deliberately get no
        // enrollment_subjects row at all.

        app(DeriveSectionDemandObservations::class)->execute();

        $key = ['academic_term_id' => $term->id, 'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'year_level' => 2];

        $observationA = SectionDemandObservation::where($key + ['subject_id' => $subjectA->id])->sole();
        $this->assertSame(1, $observationA->enrolled_count);
        $this->assertSame(2, $observationA->section_count);
        $this->assertSame(70, $observationA->offered_capacity);

        $observationB = SectionDemandObservation::where($key + ['subject_id' => $subjectB->id])->sole();
        $this->assertSame(0, $observationB->enrolled_count);
        $this->assertSame(1, $observationB->section_count);
        $this->assertSame(25, $observationB->offered_capacity);
    }

    private function termIdForStudentSubject(string $studentNumber, string $subjectCode): int
    {
        $student = StudentProfile::query()->where('student_number', $studentNumber)->sole();
        $subjectId = Subject::query()->where('code', $subjectCode)->value('id');
        $enrollmentIds = Enrollment::query()->where('student_id', $student->id)->pluck('id');

        $section = Section::query()
            ->where('subject_id', $subjectId)
            ->whereHas('enrollmentSubjects', fn ($q) => $q->whereIn('enrollment_id', $enrollmentIds))
            ->firstOrFail();

        return $section->academic_term_id;
    }

    private function fixturePath(): string
    {
        return __DIR__.'/../../../fixtures/students-profile-sample.md';
    }
}
