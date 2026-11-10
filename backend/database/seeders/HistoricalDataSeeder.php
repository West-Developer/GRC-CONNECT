<?php

namespace Database\Seeders;

use App\Actions\Analytics\DeriveSectionDemandObservations;
use App\Domain\Academic\GradeMark;
use App\Domain\Academic\GradeStatus;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\FinancialStatus;
use App\Domain\Identity\StudentType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CapacitySource;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\SectionBlockCode;
use App\Domain\Organization\SectionPlanStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Seeds comprehensive historical student cohorts (entry years 2014-2023)
 * across all 12 GRC programs, with enrollments, section blocks, locked grades,
 * graduates, and derived section demand observations (2017-2018 to 2025-2026).
 */
final class HistoricalDataSeeder extends Seeder
{
    private const FIRST_NAMES = [
        'James', 'Mary', 'John', 'Patricia', 'Robert', 'Jennifer', 'Michael', 'Linda',
        'William', 'Elizabeth', 'David', 'Barbara', 'Richard', 'Susan', 'Joseph', 'Jessica',
        'Thomas', 'Sarah', 'Charles', 'Karen', 'Christopher', 'Nancy', 'Daniel', 'Lisa',
        'Matthew', 'Betty', 'Anthony', 'Margaret', 'Mark', 'Sandra', 'Donald', 'Ashley',
        'Steven', 'Kimberly', 'Paul', 'Emily', 'Andrew', 'Donna', 'Joshua', 'Michelle',
        'Kenneth', 'Dorothy', 'Kevin', 'Carol', 'Brian', 'Amanda', 'George', 'Melissa',
        'Edward', 'Deborah', 'Ronald', 'Stephanie', 'Timothy', 'Rebecca', 'Jason', 'Sharon',
        'Jeffrey', 'Laura', 'Ryan', 'Cynthia', 'Jacob', 'Kathleen', 'Gary', 'Amy',
        'Nicholas', 'Shirley', 'Eric', 'Angela', 'Jonathan', 'Helen', 'Stephen', 'Anna',
        'Larry', 'Brenda', 'Justin', 'Pamela', 'Scott', 'Nicole', 'Brandon', 'Emma',
        'Benjamin', 'Samantha', 'Samuel', 'Katherine', 'Gregory', 'Christine', 'Frank', 'Debra',
        'Alexander', 'Rachel', 'Raymond', 'Catherine', 'Patrick', 'Carolyn', 'Jack', 'Janet',
    ];

    private const LAST_NAMES = [
        'Dela Cruz', 'Santos', 'Reyes', 'Bautista', 'Aquino', 'Garcia', 'Mendoza', 'Torres',
        'Villanueva', 'Castillo', 'Ramos', 'Flores', 'Navarro', 'Aguilar', 'Salazar', 'Cruz',
        'Mercado', 'Domingo', 'Valdez', 'Salvador', 'Fernandez', 'Aragon', 'Bautista', 'de Leon',
        'Soriano', 'Tolentino', 'Manalo', 'Rivera', 'Castro', 'Corpuz', 'David', 'Espiritu',
        'Guevarra', 'Hernandez', 'Ignacio', 'Jimenez', 'Lim', 'Magat', 'Natividad', 'Ocampo',
        'Pascual', 'Quinto', 'Rosales', 'Santiago', 'Tan', 'Umali', 'Vergara', 'Yambao',
        'Zapata', 'Alcantara', 'Bernardo', 'Cabrera', 'De Jesus', 'Enriquez', 'Francisco', 'Gomez',
    ];

    private string $passwordHash;

    public function run(): void
    {
        $this->guardEnvironment();

        $this->passwordHash = Hash::make('password');

        $programs = Program::query()
            ->whereNotNull('college')
            ->where('code', '!=', 'BSCRIM')
            ->get()
            ->keyBy('code');

        $terms = AcademicTerm::query()
            ->orderBy('school_year')
            ->orderBy('semester')
            ->get();

        $termsBySySem = [];
        foreach ($terms as $t) {
            $termsBySySem[$t->school_year][$t->semester] = $t;
        }

        $curricula = Curriculum::query()->with('curriculumSubjects.subject')->get();
        $curriculaByProgramAndStart = [];
        foreach ($curricula as $c) {
            $curriculaByProgramAndStart[$c->program_id][$c->effective_start_year] = $c;
        }

        // Cohorts to seed: Entry years from 2014 to 2023
        // 2014 enters 2014-2015 -> 4th year in 2017-2018 (graduates in 2017-2018)
        // 2015 enters 2015-2016 -> 4th year in 2018-2019 (graduates in 2018-2019)
        // ...
        // 2022 enters 2022-2023 -> 4th year in 2025-2026 (graduates in 2025-2026)
        // 2023 enters 2023-2024 -> 4th year in 2026-2027 (in progress)
        $entryYears = [2014, 2015, 2016, 2017, 2018, 2019, 2020, 2021, 2022, 2023];

        $studentsPerCohort = 25; // 25 students * 12 programs * 10 entry years = 3,000 historical students

        $this->command?->info('Seeding historical student records, enrollments, and grades...');

        $studentCounter = 10000;

        foreach ($programs as $program) {
            $isTcp = ($program->code === 'TCP');
            $maxYearLevel = $isTcp ? 1 : 4;

            foreach ($entryYears as $entryYear) {
                // Determine appropriate curriculum
                $currStartYear = 2024;
                if ($entryYear < 2018) {
                    $currStartYear = 2012;
                } elseif ($entryYear < 2024) {
                    $currStartYear = 2018;
                }

                $curriculum = $curriculaByProgramAndStart[$program->id][$currStartYear] ?? null;
                if ($curriculum === null) {
                    continue;
                }

                for ($s = 0; $s < $studentsPerCohort; $s++) {
                    $studentCounter++;
                    $studentNumber = sprintf('HIST-%04d-%05d', $entryYear, $studentCounter);
                    $firstName = self::FIRST_NAMES[($studentCounter * 7) % count(self::FIRST_NAMES)];
                    $lastName = self::LAST_NAMES[($studentCounter * 11) % count(self::LAST_NAMES)];
                    $middleInitial = chr(65 + ($studentCounter % 26));
                    $fullName = "{$lastName}, {$firstName} {$middleInitial}.";
                    $email = sprintf('hist.%s.%d.%d@grc.test', strtolower($program->code), $entryYear, $s + 1);

                    // Student fate: 85% graduate on time, 10% irregular/delayed, 5% withdraw early
                    $fateRoll = ($studentCounter % 100);
                    $willWithdraw = ($fateRoll < 5);
                    $isIrregular = ($fateRoll >= 5 && $fateRoll < 15);
                    $willGraduate = ! $willWithdraw;

                    $withdrawYear = $willWithdraw ? (1 + ($studentCounter % 2)) : 5;

                    $user = User::updateOrCreate(
                        ['email' => $email],
                        [
                            'name' => $fullName,
                            'first_name' => $firstName,
                            'middle_initial' => $middleInitial,
                            'last_name' => $lastName,
                            'password' => $this->passwordHash,
                            'role' => UserRole::Student,
                            'college' => $program->college,
                            'status' => $willGraduate ? UserStatus::Disabled : ($willWithdraw ? UserStatus::Disabled : UserStatus::Active),
                        ]
                    );

                    $finalGradSy = sprintf('%d-%d', $entryYear + $maxYearLevel - 1, $entryYear + $maxYearLevel);
                    $admissionStatus = $willGraduate && ($entryYear + $maxYearLevel <= 2026)
                        ? AdmissionStatus::Graduated
                        : ($willWithdraw ? AdmissionStatus::Withdrawn : AdmissionStatus::Enrolled);

                    $profile = StudentProfile::updateOrCreate(
                        ['user_id' => $user->id],
                        [
                            'student_number' => $studentNumber,
                            'program_id' => $program->id,
                            'curriculum_id' => $curriculum->id,
                            'entry_year' => $entryYear,
                            'year_level' => min($maxYearLevel, max(1, 2026 - $entryYear + 1)),
                            'student_type' => StudentType::Freshman,
                            'admission_status' => $admissionStatus,
                            'graduation_school_year' => $admissionStatus === AdmissionStatus::Graduated ? $finalGradSy : null,
                            'academic_standing' => $isIrregular ? AcademicStanding::Warning : AcademicStanding::Good,
                            'financial_status' => FinancialStatus::Payee,
                            'is_demo_account' => false,
                        ]
                    );

                    // Now simulate enrollments semester by semester
                    for ($yr = 1; $yr <= $maxYearLevel; $yr++) {
                        if ($yr >= $withdrawYear) {
                            break;
                        }

                        $syYear = $entryYear + ($yr - 1);
                        $syString = sprintf('%d-%d', $syYear, $syYear + 1);

                        foreach (['1st', '2nd'] as $sem) {
                            $term = $termsBySySem[$syString][$sem] ?? null;
                            if ($term === null) {
                                continue;
                            }

                            // Don't enroll past 2026-2027 1st sem
                            if ($syYear > 2026 || ($syYear === 2026 && $sem === '2nd')) {
                                continue;
                            }

                            $this->enrollStudentInSemester(
                                $profile,
                                $program,
                                $curriculum,
                                $term,
                                $yr,
                                $sem,
                                $s,
                                $isIrregular,
                                $studentCounter
                            );
                        }
                    }
                }
            }
        }

        $this->command?->info('Deriving section demand observations from historical enrollments...');
        $deriveAction = app(DeriveSectionDemandObservations::class);
        $totalDerived = 0;
        foreach ($terms as $t) {
            $totalDerived += $deriveAction->execute($t);
        }
        $this->command?->info("Completed. Derived {$totalDerived} section demand observations.");
    }

    private function enrollStudentInSemester(
        StudentProfile $profile,
        Program $program,
        Curriculum $curriculum,
        AcademicTerm $term,
        int $yearLevel,
        string $semester,
        int $studentIndex,
        bool $isIrregular,
        int $seed
    ): void {
        // Find curriculum subjects for this year level and semester
        $placements = $curriculum->curriculumSubjects
            ->filter(function (CurriculumSubject $cs) use ($yearLevel, $semester): bool {
                return $cs->year_level === $yearLevel && (
                    $cs->semester === $semester || str_contains($cs->semester, $semester)
                );
            });

        if ($placements->isEmpty()) {
            return;
        }

        // Ensure AcademicTermSectionPlan exists
        $plan = AcademicTermSectionPlan::firstOrCreate(
            [
                'academic_term_id' => $term->id,
                'curriculum_id' => $curriculum->id,
                'college' => $program->college->value,
                'year_level' => $yearLevel,
            ],
            [
                'section_count' => 1,
                'students_per_block' => 40,
                'recommendation_source' => 'manual',
                'status' => SectionPlanStatus::Submitted,
            ]
        );

        $blockNumber = (int) floor($studentIndex / 40) + 1;
        $sectionCode = SectionBlockCode::fromProgram(
            $program->code,
            $program->college,
            $yearLevel,
            $blockNumber
        );

        $enrollment = Enrollment::updateOrCreate(
            [
                'student_id' => $profile->id,
                'academic_term_id' => $term->id,
            ],
            [
                'status' => EnrollmentStatus::Enrolled,
                'enrolled_at' => $term->created_at ?? now(),
                'total_units' => $placements->sum(fn ($p) => $p->subject->units ?? 3),
            ]
        );

        foreach ($placements as $placement) {
            /** @var Subject $subject */
            $subject = $placement->subject;

            // Find or create Section
            $section = Section::firstOrCreate(
                [
                    'academic_term_id' => $term->id,
                    'subject_id' => $subject->id,
                    'section_code' => $sectionCode,
                ],
                [
                    'section_plan_id' => $plan->id,
                    'capacity' => 40,
                    'capacity_source' => CapacitySource::Plan,
                    'is_block_exclusive' => true,
                    'status' => SectionStatus::Closed,
                ]
            );

            EnrollmentSubject::firstOrCreate(
                [
                    'enrollment_id' => $enrollment->id,
                    'section_id' => $section->id,
                ],
                [
                    'status' => EnrollmentSubjectStatus::Enrolled,
                ]
            );

            // Grade generation: realistic mark distribution
            // High GPA students (top 15%): 1.00 - 1.50
            // Normal passing (70%): 1.75 - 2.50
            // Borderline (10%): 2.75 - 3.00
            // Failed (5%): 5.00
            $gradeSeed = ($seed + $subject->id + $term->id) % 100;
            if ($isIrregular && ($gradeSeed < 25)) {
                $mark = GradeMark::Failed;
                $finalGrade = 5.00;
            } elseif ($gradeSeed < 15) {
                $marks = [GradeMark::Excellent, GradeMark::HighDistinction, GradeMark::WithDistinction];
                $mark = $marks[$gradeSeed % 3];
                $finalGrade = (float) $mark->value;
            } elseif ($gradeSeed < 85) {
                $marks = [GradeMark::VeryGood, GradeMark::Good, GradeMark::VerySatisfactory, GradeMark::Satisfactory];
                $mark = $marks[$gradeSeed % 4];
                $finalGrade = (float) $mark->value;
            } elseif ($gradeSeed < 95) {
                $marks = [GradeMark::Fair, GradeMark::Passed];
                $mark = $marks[$gradeSeed % 2];
                $finalGrade = (float) $mark->value;
            } else {
                $mark = GradeMark::Failed;
                $finalGrade = 5.00;
            }

            $facultyUser = User::query()->where('role', UserRole::Faculty)->first() ?? User::query()->first();

            AcademicGrade::updateOrCreate(
                [
                    'student_id' => $profile->id,
                    'subject_id' => $subject->id,
                    'academic_term_id' => $term->id,
                ],
                [
                    'section_id' => $section->id,
                    'final_grade' => $finalGrade,
                    'mark' => $mark,
                    'remarks' => $mark === GradeMark::Failed ? 'Failed' : 'Passed',
                    'status' => GradeStatus::Locked,
                    'encoded_by' => $facultyUser->id,
                    'submitted_at' => $term->closed_at ?? now(),
                    'locked_at' => $term->closed_at ?? now(),
                ]
            );
        }
    }

    private function guardEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'HistoricalDataSeeder may only run in local or testing environment.',
            );
        }
    }
}
