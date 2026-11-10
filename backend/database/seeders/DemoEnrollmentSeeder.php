<?php

namespace Database\Seeders;

use App\Actions\Academic\ReclassifyStudentEnrollmentCategory;
use App\Domain\Academic\GradeStatus;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Curriculum\CurriculumVersion;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\SectionPlanStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\FacultyAvailability;
use App\Models\FacultySubjectPreference;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Seeds eight student logins with real locked grade history, so
 * `ClassifyEnrollmentStanding` can derive each one's Regular/Irregular
 * standing for real — never hard-coded — and every year level (plus the
 * irregular audience) is immediately testable end to end: log in, browse
 * the derived prospectus/grade slip, and submit a fresh enrollment against
 * the current `semester_ongoing` term (see AcademicTermSeeder), since none
 * of the eight carry an enrollment of their own yet.
 *
 *   0001  1st year, regular    — 1 completed semester
 *   0002  2nd year, regular    — 3 completed semesters
 *   0003  3rd year, regular    — 5 completed semesters
 *   0004  4th year, regular    — 7 completed semesters
 *   0005  2nd year, irregular  — 3 sems, a 5.00 (Failed) on a required subject
 *   0006  2nd year, irregular  — 3 sems, an INC (Incomplete) on a required subject
 *   0007  3rd year, irregular  — 5 sems, an NC (Not Complete) on a Leadership subject
 *   0008  4th year, irregular  — 7 sems, missing a required subject's grade entirely
 *
 * Each student's completed-semester count follows the real BSIT curriculum's
 * own ordinal positions exactly — SemesterSlot::
 * ordinal() is `(yearLevel-1)*2 + (1|2)` — so a year-Y student has
 * `(Y-1)*2 + 1` completed ordinals and is about to enroll in their Yth
 * year's 2nd semester, which is exactly the current ongoing term.
 *
 * Grades are recorded against the 7 non-ongoing terms AcademicTermSeeder
 * seeds (2023-2024 1st through 2026-2027 1st), in chronological order,
 * right-aligned so a student's MOST RECENT completed ordinal always lands on
 * the most recent closed term (2026-2027 1st) — a year-4 student's 7
 * ordinals therefore use all 7 terms 1:1, and shorter histories use however
 * many of the most recent terms they need.
 *
 * NONE of this is real student data. Names, student numbers, and grades are
 * invented placeholders, and the emails use the reserved `.test` TLD
 * (RFC 2606) so they can never resolve to a real mailbox.
 *
 * Depends on RoleUserSeeder, ProgramSeeder, SubjectSeeder, CurriculumSeeder,
 * and AcademicTermSeeder.
 */
final class DemoEnrollmentSeeder extends Seeder
{
    private const PASSWORD = 'password';

    /**
     * Every REAL BSIT subject at each curriculum ordinal (from
     * `data/curriculum-2024-2029-placements.csv`), with the mark a REGULAR
     * student earns there. Every required subject gets '2.00' except that
     * ordinal's Leadership subject, which gets 'C' (completion-only — see
     * `CompletionOnlySubjectRule`). Ordinal 8 (year 4, 2nd semester) is
     * deliberately absent — that is the current ongoing term every student
     * is left free to enroll into themselves.
     *
     * @var array<int, list<array{subject: string, mark: string}>>
     */
    private const SEMESTER_SUBJECTS = [
        1 => [
            ['subject' => 'ITC', 'mark' => '2.00'],
            ['subject' => 'ITCL', 'mark' => '2.00'],
            ['subject' => 'ITP1', 'mark' => '2.00'],
            ['subject' => 'ITP1L', 'mark' => '2.00'],
            ['subject' => 'ITP2', 'mark' => '2.00'],
            ['subject' => 'ITP2L', 'mark' => '2.00'],
            ['subject' => 'KOMFIL', 'mark' => '2.00'],
            ['subject' => 'LEAD 1', 'mark' => 'C'],
            ['subject' => 'MATHWRLD', 'mark' => '2.00'],
            ['subject' => 'NSTP 1', 'mark' => '2.00'],
            ['subject' => 'PATHFIT1', 'mark' => '2.00'],
            ['subject' => 'PHILHIST', 'mark' => '2.00'],
            ['subject' => 'PURPCOMM', 'mark' => '2.00'],
            ['subject' => 'UNDSELF', 'mark' => '2.00'],
        ],
        2 => [
            ['subject' => 'CONWRLD', 'mark' => '2.00'],
            ['subject' => 'ETHICS', 'mark' => '2.00'],
            ['subject' => 'HCI', 'mark' => '2.00'],
            ['subject' => 'HCIL', 'mark' => '2.00'],
            ['subject' => 'ITPLUS3', 'mark' => '2.00'],
            ['subject' => 'ITPLUS4', 'mark' => '2.00'],
            ['subject' => 'LEAD2', 'mark' => 'C'],
            ['subject' => 'NSTP2', 'mark' => '2.00'],
            ['subject' => 'PATHFIT2', 'mark' => '2.00'],
            ['subject' => 'PROG1', 'mark' => '2.00'],
            ['subject' => 'PROG1L', 'mark' => '2.00'],
            ['subject' => 'PSPEAK', 'mark' => '2.00'],
            ['subject' => 'SCITECH', 'mark' => '2.00'],
        ],
        3 => [
            ['subject' => 'AVE', 'mark' => '2.00'],
            ['subject' => 'AVEL', 'mark' => '2.00'],
            ['subject' => 'CPROG2', 'mark' => '2.00'],
            ['subject' => 'CPROG2L', 'mark' => '2.00'],
            ['subject' => 'DBMSYS', 'mark' => '2.00'],
            ['subject' => 'DBMSYSL', 'mark' => '2.00'],
            ['subject' => 'ENVISCI', 'mark' => '2.00'],
            ['subject' => 'IPT1', 'mark' => '2.00'],
            ['subject' => 'IPT1L', 'mark' => '2.00'],
            ['subject' => 'LEAD 3', 'mark' => 'C'],
            ['subject' => 'NW1', 'mark' => '2.00'],
            ['subject' => 'NW1L', 'mark' => '2.00'],
            ['subject' => 'PATHFIT3', 'mark' => '2.00'],
            ['subject' => 'WST', 'mark' => '2.00'],
            ['subject' => 'WSTL', 'mark' => '2.00'],
        ],
        4 => [
            ['subject' => 'DSTRUCT', 'mark' => '2.00'],
            ['subject' => 'DSTRUCTL', 'mark' => '2.00'],
            ['subject' => 'INFOMGT', 'mark' => '2.00'],
            ['subject' => 'INFOMGTL', 'mark' => '2.00'],
            ['subject' => 'LEAD4', 'mark' => 'C'],
            ['subject' => 'NET2', 'mark' => '2.00'],
            ['subject' => 'NET2L', 'mark' => '2.00'],
            ['subject' => 'OOP', 'mark' => '2.00'],
            ['subject' => 'OOPL', 'mark' => '2.00'],
            ['subject' => 'PATHFIT4', 'mark' => '2.00'],
            ['subject' => 'PROFELEC1', 'mark' => '2.00'],
            ['subject' => 'PROFELEC1L', 'mark' => '2.00'],
            ['subject' => 'SOSLIT', 'mark' => '2.00'],
            ['subject' => 'SYSARCH1', 'mark' => '2.00'],
            ['subject' => 'SYSARCH1L', 'mark' => '2.00'],
        ],
        5 => [
            ['subject' => 'ARTAPP', 'mark' => '2.00'],
            ['subject' => 'BMC', 'mark' => '2.00'],
            ['subject' => 'BMCL', 'mark' => '2.00'],
            ['subject' => 'CAO', 'mark' => '2.00'],
            ['subject' => 'CAOL', 'mark' => '2.00'],
            ['subject' => 'DMATH', 'mark' => '2.00'],
            ['subject' => 'LEAD 5', 'mark' => 'C'],
            ['subject' => 'PRELEC2', 'mark' => '2.00'],
            ['subject' => 'PRELEC2L', 'mark' => '2.00'],
            ['subject' => 'PT', 'mark' => '2.00'],
            ['subject' => 'PTL', 'mark' => '2.00'],
            ['subject' => 'SIA2', 'mark' => '2.00'],
            ['subject' => 'SIA2L', 'mark' => '2.00'],
        ],
        6 => [
            ['subject' => 'ADVMOB', 'mark' => '2.00'],
            ['subject' => 'ADVMOBL', 'mark' => '2.00'],
            ['subject' => 'ARCHORG', 'mark' => '2.00'],
            ['subject' => 'ARCHORGL', 'mark' => '2.00'],
            ['subject' => 'CAPSTONE1', 'mark' => '2.00'],
            ['subject' => 'CAPSTONE1L', 'mark' => '2.00'],
            ['subject' => 'INFOSEC1', 'mark' => '2.00'],
            ['subject' => 'INFOSEC1L', 'mark' => '2.00'],
            ['subject' => 'LEAD6', 'mark' => 'C'],
            ['subject' => 'QMTHODS', 'mark' => '2.00'],
            ['subject' => 'RIZAL', 'mark' => '2.00'],
            ['subject' => 'WEBSYS', 'mark' => '2.00'],
            ['subject' => 'WEBSYSL', 'mark' => '2.00'],
        ],
        7 => [
            ['subject' => 'BUSANA', 'mark' => '2.00'],
            ['subject' => 'CAPS2', 'mark' => '2.00'],
            ['subject' => 'CAPS2L', 'mark' => '2.00'],
            ['subject' => 'IAS2', 'mark' => '2.00'],
            ['subject' => 'IAS2L', 'mark' => '2.00'],
            ['subject' => 'LEAD 7', 'mark' => 'C'],
            ['subject' => 'SPI', 'mark' => '2.00'],
        ],
    ];

    /**
     * The current ongoing term's own real BSIT subjects — one entry per
     * year level 1–4, what a REGULAR student in that year is about to
     * enroll into. Years 1–3 are exactly `SEMESTER_SUBJECTS[2]`/`[4]`/`[6]`'s
     * own subject codes (a year-Y student's current target IS year Y's
     * 2nd semester, ordinal `2*Y`); year 4's list is wholly new since
     * ordinal 8 (year 4 2nd semester) is deliberately absent from
     * `SEMESTER_SUBJECTS`.
     *
     * @var array<int, list<string>>
     */
    private const BLOCK_SUBJECTS_BY_YEAR = [
        1 => ['CONWRLD', 'ETHICS', 'HCI', 'HCIL', 'ITPLUS3', 'ITPLUS4', 'LEAD2', 'NSTP2', 'PATHFIT2', 'PROG1', 'PROG1L', 'PSPEAK', 'SCITECH'],
        2 => ['DSTRUCT', 'DSTRUCTL', 'INFOMGT', 'INFOMGTL', 'LEAD4', 'NET2', 'NET2L', 'OOP', 'OOPL', 'PATHFIT4', 'PROFELEC1', 'PROFELEC1L', 'SOSLIT', 'SYSARCH1', 'SYSARCH1L'],
        3 => ['ADVMOB', 'ADVMOBL', 'ARCHORG', 'ARCHORGL', 'CAPSTONE1', 'CAPSTONE1L', 'INFOSEC1', 'INFOSEC1L', 'LEAD6', 'QMTHODS', 'RIZAL', 'WEBSYS', 'WEBSYSL'],
        4 => ['LEAD8', 'OJT', 'SYSMAIN', 'SYSMAINL'],
    ];

    /**
     * Year levels 1–3 offer a real choice — three block sections, so a
     * regular student picks among them instead of being handed the only
     * option. Year 4 keeps a single block: PRD scope only asked for a real
     * choice at years 1–3. Codes follow the school's own convention —
     * program prefix, year level, two-digit section number — not a "DEMO"
     * placeholder, so the enrollment screen shows exactly what a student
     * would see in production (e.g. "BSIT101", not "Block DEMO1A").
     *
     * @var array<int, list<string>>
     */
    private const BLOCK_CODES_BY_YEAR = [
        1 => ['BSIT101', 'BSIT102', 'BSIT103'],
        2 => ['BSIT201', 'BSIT202', 'BSIT203'],
        3 => ['BSIT301', 'BSIT302', 'BSIT303'],
        4 => ['BSIT401'],
    ];

    /**
     * One distinct single-day weekly schedule per block letter (A/B/C), so
     * the three blocks in a year level are a meaningfully different choice
     * — not the same slot copy-pasted under three codes. Each subject in a
     * block meets exactly one day, matching how every real GRC schedule row
     * works (verified directly against both source Excel files). Indexed
     * positionally against `BLOCK_CODES_BY_YEAR`'s inner arrays.
     *
     * @var list<array{day: string, start_hour: int, room: string}>
     */
    private const BLOCK_SCHEDULES = [
        ['day' => 'Mon', 'start_hour' => 8, 'room' => 'RM-401'],
        ['day' => 'Wed', 'start_hour' => 8, 'room' => 'RM-402'],
        ['day' => 'Fri', 'start_hour' => 13, 'room' => 'RM-403'],
    ];

    /**
     * The 7 non-ongoing terms, oldest first — a completed ordinal always
     * maps onto the tail end of this list (see class docblock).
     *
     * @var list<array{school_year: string, semester: string}>
     */
    private const CHRONOLOGICAL_TERMS = [
        ['school_year' => '2023-2024', 'semester' => '1st'],
        ['school_year' => '2023-2024', 'semester' => '2nd'],
        ['school_year' => '2024-2025', 'semester' => '1st'],
        ['school_year' => '2024-2025', 'semester' => '2nd'],
        ['school_year' => '2025-2026', 'semester' => '1st'],
        ['school_year' => '2025-2026', 'semester' => '2nd'],
        ['school_year' => '2026-2027', 'semester' => '1st'],
    ];

    /**
     * `overrides` replaces a subject's regular mark for that one student;
     * `omit` skips recording a mark for that subject entirely (a genuinely
     * missing required grade, not a special mark).
     *
     * @var list<array{
     *     number: string, email: string, name: string, yearLevel: int,
     *     completedOrdinals: int, overrides: array<string, string>, omit: list<string>
     * }>
     */
    private const STUDENTS = [
        ['number' => '2023-06-00001', 'email' => 'student.seed@grc.test', 'name' => 'Seed Student', 'yearLevel' => 1, 'completedOrdinals' => 1, 'overrides' => [], 'omit' => []],
        ['number' => '2023-06-00002', 'email' => 'student2.seed@grc.test', 'name' => 'Seed Student Two', 'yearLevel' => 2, 'completedOrdinals' => 3, 'overrides' => [], 'omit' => []],
        ['number' => '2023-06-00003', 'email' => 'student3.seed@grc.test', 'name' => 'Seed Student Three', 'yearLevel' => 3, 'completedOrdinals' => 5, 'overrides' => [], 'omit' => []],
        ['number' => '2023-06-00004', 'email' => 'student4.seed@grc.test', 'name' => 'Seed Student Four', 'yearLevel' => 4, 'completedOrdinals' => 7, 'overrides' => [], 'omit' => []],
        ['number' => '2023-06-00005', 'email' => 'student5.seed@grc.test', 'name' => 'Seed Student Five', 'yearLevel' => 2, 'completedOrdinals' => 3, 'overrides' => ['MATHWRLD' => '5.00'], 'omit' => []],
        ['number' => '2023-06-00006', 'email' => 'student6.seed@grc.test', 'name' => 'Seed Student Six', 'yearLevel' => 2, 'completedOrdinals' => 3, 'overrides' => ['PROG1' => 'INC'], 'omit' => []],
        ['number' => '2023-06-00007', 'email' => 'student7.seed@grc.test', 'name' => 'Seed Student Seven', 'yearLevel' => 3, 'completedOrdinals' => 5, 'overrides' => ['LEAD 5' => 'NC'], 'omit' => []],
        ['number' => '2023-06-00008', 'email' => 'student8.seed@grc.test', 'name' => 'Seed Student Eight', 'yearLevel' => 4, 'completedOrdinals' => 7, 'overrides' => ['SPI' => '5.00'], 'omit' => []],
    ];

    /**
     * Two more logins on the REAL `BSIT` program, demonstrating the
     * Registrar's exact versioning scenario: a student who entered in 2023
     * is the last batch of the old curriculum and is now a 4th-year 2nd-sem
     * student on it, while a 2024-entry student already follows the new
     * curriculum and is a 3rd-year 2nd-sem student on it. Neither carries
     * grade history or a block enrollment — the 8 students above already
     * exercise that lifecycle, now on this same real BSIT program; these two
     * exist solely to make `CurriculumVersion::resolveForEntryYear()` visible
     * end to end (log in, open the prospectus, see the correct curriculum's
     * subject list).
     *
     * @var list<array{number: string, email: string, name: string, entryYear: int, yearLevel: int}>
     */
    private const CURRICULUM_VERSION_STUDENTS = [
        ['number' => '2023-06-00100', 'email' => 'student.oldcurriculum.seed@grc.test', 'name' => 'Seed Student (2023 Entry, Old Curriculum)', 'entryYear' => 2023, 'yearLevel' => 4],
        ['number' => '2024-06-00101', 'email' => 'student.newcurriculum.seed@grc.test', 'name' => 'Seed Student (2024 Entry, New Curriculum)', 'entryYear' => 2024, 'yearLevel' => 3],
    ];

    public function run(): void
    {
        $this->guardEnvironment();

        DB::transaction(function (): void {
            $program = $this->program();
            $curriculum = $this->curriculum();
            $encoder = $this->userWithRole(UserRole::Faculty);

            $profiles = new Collection;

            foreach (self::STUDENTS as $definition) {
                $profile = $this->studentProfile($definition, $program, $curriculum);
                $this->seedGradeHistory($profile, $definition, $encoder);
                $profiles->push($profile);
            }

            $this->seedRegularBlocks($curriculum, $encoder);
            $this->reclassify($profiles);
            $this->seedCurriculumVersionDemoStudents();
        });
    }

    /**
     * Seeds `CURRICULUM_VERSION_STUDENTS` — see that constant's docblock.
     * Silently does nothing if the real `BSIT` program or its curriculum
     * versions are not seeded (e.g. a test seeding only `DemoEnrollmentSeeder`
     * in isolation without `GrcCurriculumSeeder`), since this demonstration
     * is additive and never required by the grade-history roster.
     */
    private function seedCurriculumVersionDemoStudents(): void
    {
        $program = Program::query()->where('code', 'BSIT')->first();

        if ($program === null) {
            return;
        }

        $curricula = Curriculum::query()->where('program_id', $program->id)->get();

        foreach (self::CURRICULUM_VERSION_STUDENTS as $definition) {
            $curriculum = CurriculumVersion::resolveForEntryYear($curricula, $definition['entryYear']);

            if ($curriculum === null) {
                continue;
            }

            $user = User::updateOrCreate(
                ['email' => $definition['email']],
                [
                    'name' => $definition['name'],
                    'password' => self::PASSWORD,
                    'role' => UserRole::Student,
                    'status' => UserStatus::Active,
                ],
            );

            StudentProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'student_number' => $definition['number'],
                    'program_id' => $program->id,
                    'curriculum_id' => $curriculum->id,
                    'entry_year' => $definition['entryYear'],
                    'year_level' => $definition['yearLevel'],
                    'admission_status' => AdmissionStatus::Admitted,
                    'academic_standing' => AcademicStanding::Good,
                    'is_demo_account' => true,
                ],
            );
        }
    }

    /**
     * Generated blocks for year levels 1–4 on the current ongoing term, so
     * `BuildEnrollmentBlockPool` has something real to offer the four
     * REGULAR seeded students (0001–0004) — otherwise "Select your section"
     * renders "No sections were generated" and none of them can actually
     * complete a fresh enrollment, defeating the entire point of this
     * roster. Years 1–3 get three blocks (a real choice); year 4 gets one —
     * see `BLOCK_CODES_BY_YEAR`. `college` is a required column on
     * `academic_term_section_plans` but is never read by the block-pool
     * lookup itself (which matches on `year_level`/`curriculum_id` only) —
     * `'demo'` is a harmless placeholder, not a real `CollegeCode`.
     */
    private function seedRegularBlocks(Curriculum $curriculum, User $encoder): void
    {
        $currentTerm = AcademicTerm::query()
            ->where('status', AcademicTermStatus::SemesterOngoing)
            ->first();

        if ($currentTerm === null) {
            return;
        }

        $professorsBySubject = $this->seedProfessors($currentTerm, $curriculum);

        foreach (self::BLOCK_SUBJECTS_BY_YEAR as $yearLevel => $subjectCodes) {
            $blockCodes = self::BLOCK_CODES_BY_YEAR[$yearLevel];

            $plan = AcademicTermSectionPlan::updateOrCreate(
                [
                    'academic_term_id' => $currentTerm->id,
                    'curriculum_id' => $curriculum->id,
                    'college' => 'demo',
                    'year_level' => $yearLevel,
                ],
                [
                    'section_count' => count($blockCodes),
                    'students_per_block' => 40,
                    'status' => SectionPlanStatus::Submitted,
                    'submitted_by' => $encoder->id,
                    'submitted_at' => now(),
                ],
            );

            foreach ($blockCodes as $blockIndex => $blockCode) {
                $schedule = self::BLOCK_SCHEDULES[$blockIndex];

                foreach ($subjectCodes as $subjectIndex => $subjectCode) {
                    $subject = $this->subject($subjectCode);
                    $startHour = $schedule['start_hour'] + $subjectIndex;
                    $professor = $professorsBySubject[$subjectCode] ?? null;

                    Section::updateOrCreate(
                        [
                            'academic_term_id' => $currentTerm->id,
                            'subject_id' => $subject->id,
                            'section_code' => $blockCode,
                        ],
                        [
                            'section_plan_id' => $plan->id,
                            'professor_id' => $professor?->id,
                            'schedule_days' => $schedule['day'],
                            'starts_at_time' => sprintf('%02d:00:00', $startHour),
                            'ends_at_time' => sprintf('%02d:00:00', $startHour + 1),
                            'room' => $schedule['room'],
                            'capacity' => 40,
                            'capacity_source' => 'plan',
                            'is_block_exclusive' => true,
                            'status' => SectionStatus::Published,
                        ],
                    );
                }
            }
        }
    }

    /**
     * One `User` (role Faculty, college CCS) per named `reference_professor_name`
     * across the current term's own subjects (`BLOCK_SUBJECTS_BY_YEAR`), each
     * with a declared weekday 08:00–17:00 availability (covering every
     * `BLOCK_SCHEDULES` slot their sections actually meet at) and a
     * `FacultySubjectPreference` for every subject they own — real Faculty
     * Input rows, not just a `professor_id` pointer. A subject whose
     * representative block had no named professor in the source Excel gets
     * no Faculty account and its generated sections keep `professor_id =
     * null` — no name is ever invented.
     *
     * Rank is assigned per professor, not hard-coded to 1: several real
     * professors in the source Excel own more than one of this term's
     * subjects (e.g. "MR. SALAZAR" teaches both PATHFIT2 and PATHFIT4), and
     * `faculty_subject_preferences` has a `(professor_id, academic_term_id,
     * rank)` unique constraint — a professor cannot rank two different
     * subjects #1 in the same term. Follows the same `max('rank') + 1`,
     * skip-if-already-exists convention `CatalogFacultySeeder` already uses
     * for the identical problem: each professor's first subject encountered
     * (in `BLOCK_SUBJECTS_BY_YEAR` order) gets rank 1, their second gets
     * rank 2, and so on; reseeding never re-ranks an existing preference.
     *
     * @return array<string, ?User> subject code => the professor who owns it, or null
     */
    private function seedProfessors(AcademicTerm $currentTerm, Curriculum $curriculum): array
    {
        $professorsBySubject = [];
        $nextRanks = [];

        foreach (self::BLOCK_SUBJECTS_BY_YEAR as $subjectCodes) {
            foreach ($subjectCodes as $subjectCode) {
                if (array_key_exists($subjectCode, $professorsBySubject)) {
                    continue;
                }

                $subject = $this->subject($subjectCode);
                $placement = CurriculumSubject::query()
                    ->where('curriculum_id', $curriculum->id)
                    ->where('subject_id', $subject->id)
                    ->first();
                $professorName = $placement?->reference_professor_name;

                if ($professorName === null) {
                    $professorsBySubject[$subjectCode] = null;

                    continue;
                }

                $professor = User::updateOrCreate(
                    ['name' => $professorName, 'role' => UserRole::Faculty],
                    [
                        'email' => 'prof.'.Str::slug($professorName).'@grc.test',
                        'password' => self::PASSWORD,
                        'college' => CollegeCode::Ccs,
                        'status' => UserStatus::Active,
                    ],
                );

                foreach ([1, 2, 3, 4, 5] as $dayOfWeek) {
                    FacultyAvailability::updateOrCreate(
                        [
                            'professor_id' => $professor->id,
                            'day_of_week' => $dayOfWeek,
                            'starts_at_time' => '08:00:00',
                        ],
                        ['ends_at_time' => '17:00:00'],
                    );
                }

                $preference = FacultySubjectPreference::firstOrNew([
                    'professor_id' => $professor->id,
                    'academic_term_id' => $currentTerm->id,
                    'subject_id' => $subject->id,
                ]);

                if (! $preference->exists) {
                    $rankKey = "{$currentTerm->id}:{$professor->id}";
                    $nextRanks[$rankKey] ??= (int) FacultySubjectPreference::query()
                        ->where('professor_id', $professor->id)
                        ->where('academic_term_id', $currentTerm->id)
                        ->max('rank');
                    $preference->rank = ++$nextRanks[$rankKey];
                    $preference->save();
                }

                $professorsBySubject[$subjectCode] = $professor;
            }
        }

        return $professorsBySubject;
    }

    /**
     * @param  array{number: string, email: string, name: string, yearLevel: int, completedOrdinals: int, overrides: array<string, string>, omit: list<string>}  $definition
     */
    private function studentProfile(array $definition, Program $program, Curriculum $curriculum): StudentProfile
    {
        $user = User::updateOrCreate(
            ['email' => $definition['email']],
            [
                'name' => $definition['name'],
                'password' => self::PASSWORD,
                'role' => UserRole::Student,
                'status' => UserStatus::Active,
            ],
        );

        return StudentProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'student_number' => $definition['number'],
                'program_id' => $program->id,
                'curriculum_id' => $curriculum->id,
                'year_level' => $definition['yearLevel'],
                // Left unset here on purpose — ReclassifyStudentEnrollmentCategory
                // derives the real value from the grade history seeded just
                // below, for every student, every seed run.
                'enrollment_category' => null,
                'enrollment_category_derived_at' => null,
                'admission_status' => AdmissionStatus::Admitted,
                'academic_standing' => AcademicStanding::Good,
                'is_demo_account' => true,
            ],
        );
    }

    /**
     * @param  array{number: string, email: string, name: string, yearLevel: int, completedOrdinals: int, overrides: array<string, string>, omit: list<string>}  $definition
     */
    private function seedGradeHistory(StudentProfile $profile, array $definition, User $encoder): void
    {
        for ($ordinal = 1; $ordinal <= $definition['completedOrdinals']; $ordinal++) {
            $term = $this->termForOrdinal($ordinal, $definition['completedOrdinals']);

            foreach (self::SEMESTER_SUBJECTS[$ordinal] as $row) {
                if (in_array($row['subject'], $definition['omit'], true)) {
                    continue;
                }

                $mark = $definition['overrides'][$row['subject']] ?? $row['mark'];
                $subject = $this->subject($row['subject']);
                $numeric = is_numeric($mark);

                AcademicGrade::updateOrCreate(
                    [
                        'student_id' => $profile->id,
                        'subject_id' => $subject->id,
                        'academic_term_id' => $term->id,
                    ],
                    [
                        // Nullable and intentionally null: this historical
                        // result is carried without an owning section, the
                        // same precedent past demo grades have always used.
                        'section_id' => null,
                        'mark' => $mark,
                        'final_grade' => $numeric ? $mark : null,
                        'remarks' => null,
                        'status' => GradeStatus::Locked,
                        'encoded_by' => $encoder->id,
                        'submitted_at' => now(),
                        'locked_at' => now(),
                    ],
                );
            }
        }
    }

    /**
     * A completed ordinal is always right-aligned to the most recent closed
     * term — see the class docblock.
     */
    private function termForOrdinal(int $ordinal, int $completedOrdinals): AcademicTerm
    {
        $index = (7 - $completedOrdinals) + $ordinal - 1;
        $reference = self::CHRONOLOGICAL_TERMS[$index]
            ?? throw new RuntimeException("No chronological term mapped for ordinal {$ordinal}.");

        return AcademicTerm::query()
            ->where('school_year', $reference['school_year'])
            ->where('semester', $reference['semester'])
            ->firstOrFail();
    }

    /**
     * Runs the real classifier against every seeded student's newly-written
     * grade history, against the current `semester_ongoing` term — the
     * seeder proves its own correctness this way, per the approved design:
     * `enrollment_category` is never hard-coded here.
     *
     * @param  Collection<int, StudentProfile>  $profiles
     */
    private function reclassify(Collection $profiles): void
    {
        $currentTerm = AcademicTerm::query()
            ->where('status', AcademicTermStatus::SemesterOngoing)
            ->first();

        if ($currentTerm === null) {
            return;
        }

        app(ReclassifyStudentEnrollmentCategory::class)->executeMany(
            $profiles,
            $currentTerm,
            $this->userWithRole(UserRole::RegistrarHead),
            new AuditRequestContext('demo-enrollment-seed', null),
        );
    }

    /**
     * `subjects` is unique on `(college, code)`, not `code` alone, and the
     * real GRC catalog (`GrcSubjectCatalogSeeder`) seeds several of this
     * curriculum's own codes — every Leadership code (`LEAD 1`-`LEAD8`) and
     * several shared Gen-Ed codes (e.g. `KOMFIL`) alike — identically under
     * other real colleges too (COE, CBAE, COA), so an unscoped
     * `where('code', $code)` is ambiguous. Every subject this seeder ever
     * looks up belongs to the real BSIT curriculum, which
     * `curriculum-2024-2029-placements.csv` places entirely under CCS, so
     * scoping every lookup to CCS resolves the same row
     * `GrcCurriculumSeeder`/`GrcCurriculumScheduleReferenceSeeder` used for
     * that placement — or a student's grade history and the curriculum's
     * required-subject list would silently disagree on which subject_id a
     * shared code even is, which is exactly what makes
     * `ClassifyEnrollmentStanding` misread a passed grade against the wrong
     * subject_id and misclassify an otherwise-Regular student as Irregular
     * (a bogus `needs_adding_backlog` or `needs_removing_prerequisite`
     * reason).
     */
    private function subject(string $code): Subject
    {
        $subject = Subject::query()->where('college', CollegeCode::Ccs)->where('code', $code)->first();

        if ($subject === null) {
            throw new RuntimeException("Subject '{$code}' is missing. Run SubjectSeeder first.");
        }

        return $subject;
    }

    private function curriculum(): Curriculum
    {
        $program = $this->program();
        $curriculum = Curriculum::query()
            ->where('program_id', $program->id)
            ->where('status', 'active')
            ->first();

        if ($curriculum === null) {
            throw new RuntimeException(
                'DemoEnrollmentSeeder requires the real BSIT program\'s active curriculum. Run GrcCurriculumSeeder first.',
            );
        }

        return $curriculum;
    }

    private function program(): Program
    {
        $program = Program::query()->where('code', 'BSIT')->first();

        if ($program === null) {
            throw new RuntimeException(
                'DemoEnrollmentSeeder requires the seeded BSIT program. Run ProgramSeeder first.',
            );
        }

        return $program;
    }

    private function userWithRole(UserRole $role): User
    {
        $user = User::query()->where('role', $role)->first();

        if ($user === null) {
            throw new RuntimeException(
                "DemoEnrollmentSeeder requires a '{$role->value}' user. Run RoleUserSeeder first.",
            );
        }

        return $user;
    }

    /**
     * Synthetic student records must never reach a production-like environment.
     */
    private function guardEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'DemoEnrollmentSeeder may only run in the local or testing environment. '
                .'Refusing to seed synthetic student data into "'.app()->environment().'".',
            );
        }
    }
}
