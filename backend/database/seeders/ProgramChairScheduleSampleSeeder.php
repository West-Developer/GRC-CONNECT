<?php

namespace Database\Seeders;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermCollegeWorkflowStage;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\SectionBlockCode;
use App\Domain\Organization\SectionPlanStatus;
use App\Domain\Scheduling\SectionModality;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\AcademicTermCollegeWorkflow;
use App\Models\AcademicTermSectionPlan;
use App\Models\Curriculum;
use App\Models\FacultyAvailability;
use App\Models\FacultySubjectPreference;
use App\Models\ScheduleProposal;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Local-only faculty capabilities and mixed Dean/Executive approval fixtures. */
final class ProgramChairScheduleSampleSeeder extends Seeder
{
    private const PASSWORD = 'password';

    /**
     * Seats per block, one entry per year level. Deliberately distinct
     * across years (rather than one flat number) so a propagation bug —
     * capacity not actually varying by year — is visible at a glance
     * rather than hiding behind a coincidentally-uniform value.
     *
     * @var array<int, int>
     */
    private const STUDENTS_PER_BLOCK = [1 => 40, 2 => 38, 3 => 35, 4 => 30];

    /** Two blocks per year level, so the student block picker has a real choice. */
    private const BLOCKS_PER_YEAR = 2;

    public function run(): void
    {
        $this->guardEnvironment();

        DB::transaction(function (): void {
            $facultyByCollege = [];
            foreach (['ccs', 'coe', 'coa', 'cbae'] as $college) {
                $facultyByCollege[$college] = User::updateOrCreate(
                    ['email' => "faculty.sample.{$college}@grc.test"],
                    [
                        'name' => 'Sample Faculty — '.strtoupper($college),
                        'password' => self::PASSWORD,
                        'role' => UserRole::Faculty,
                        'college' => $college,
                        'status' => UserStatus::Active,
                    ],
                );
            }

            $term = AcademicTerm::query()->whereNotIn('status', ['archived', 'semester_closed'])->latest('id')->first();
            if ($term === null) {
                return;
            }

            foreach ($facultyByCollege as $college => $faculty) {
                FacultyAvailability::updateOrCreate(
                    ['professor_id' => $faculty->id, 'day_of_week' => 1, 'starts_at_time' => '08:00:00'],
                    ['ends_at_time' => '12:00:00'],
                );
                $curriculum = $this->activeCurriculumFor($college);
                if ($curriculum === null) {
                    continue;
                }

                FacultySubjectPreference::query()
                    ->where('professor_id', $faculty->id)
                    ->where('academic_term_id', $term->id)
                    ->delete();

                $placements = $curriculum->subjectPlacements()
                    ->where(function ($query) use ($term): void {
                        $query->where('semester', $term->semester)
                            ->orWhere('semester', 'like', '%'.$term->semester.'%');
                    })
                    ->get();

                foreach ($placements->values() as $index => $placement) {
                    FacultySubjectPreference::updateOrCreate(
                        ['professor_id' => $faculty->id, 'academic_term_id' => $term->id, 'subject_id' => $placement->subject_id],
                        ['rank' => $index + 1],
                    );
                }

                $chair = User::query()->where('role', UserRole::ProgramChair)->where('college', $college)->first() ?? $faculty;
                $plans = [];
                foreach (range(1, 4) as $year) {
                    $plans[$year] = AcademicTermSectionPlan::updateOrCreate(
                        ['academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id, 'college' => $college, 'year_level' => $year],
                        [
                            'section_count' => self::BLOCKS_PER_YEAR,
                            'students_per_block' => self::STUDENTS_PER_BLOCK[$year],
                            'status' => SectionPlanStatus::Submitted,
                            'submitted_by' => $chair->id,
                            'submitted_at' => now(),
                        ],
                    );
                }

                Section::query()
                    ->whereIn('section_plan_id', collect($plans)->pluck('id'))
                    ->whereRaw("section_code REGEXP '^[1-4][A-Z]$'")
                    ->delete();

                // Grouped by year first so each subject's meeting time can be
                // staggered within its own block — sharing one hardcoded
                // time slot across every subject in a block (the previous
                // shape of this seeder) makes every generated block
                // self-conflicting and unselectable. Some seeded curricula
                // place 20+ subjects in one year/semester, so slots are
                // spread across the school week (day, then hour) rather
                // than incrementing the hour alone, which would run every
                // subject past midnight on a single Monday once a block
                // has more than about sixteen subjects.
                $placementsByYear = $placements->groupBy('year_level');

                foreach ($placementsByYear as $year => $yearPlacements) {
                    // $plans always covers every year level 1-4 (created
                    // just above), so a placement's year level is always a
                    // valid key — this only guards against a curriculum
                    // subject placed at a year level outside that range.
                    $plan = $plans[(int) $year] ?? null;
                    if ($plan === null) {
                        throw new RuntimeException("No section plan exists for year level {$year} in college {$college}.");
                    }

                    for ($number = 1; $number <= self::BLOCKS_PER_YEAR; $number++) {
                        $sectionCode = SectionBlockCode::fromProgram(
                            $curriculum->program->code,
                            CollegeCode::from($college),
                            (int) $year,
                            $number,
                        );

                        foreach ($yearPlacements->values() as $index => $placement) {
                            [$day, $startHour] = $this->weekSlot($index);
                            Section::updateOrCreate(
                                ['academic_term_id' => $term->id, 'subject_id' => $placement->subject_id, 'section_code' => $sectionCode],
                                [
                                    'section_plan_id' => $plan->id,
                                    'professor_id' => $faculty->id,
                                    'schedule_days' => $day,
                                    'starts_at_time' => sprintf('%02d:00:00', $startHour),
                                    'ends_at_time' => sprintf('%02d:30:00', $startHour),
                                    'room' => 'Room 101',
                                    'modality' => SectionModality::FaceToFace,
                                    'capacity' => self::STUDENTS_PER_BLOCK[(int) $year],
                                    'capacity_source' => 'plan',
                                    'is_block_exclusive' => true,
                                    'status' => SectionStatus::Planned,
                                ],
                            );
                        }
                    }
                }

                // $plans always covers year level 1 (built just above via
                // range(1, 4)) — this only guards the same way line 135
                // does, since PHPStan can't prove an incrementally-built
                // array's key presence statically.
                $primaryPlan = $plans[1] ?? null;
                if ($primaryPlan === null) {
                    throw new RuntimeException("No section plan exists for year level 1 in college {$college}.");
                }

                ScheduleProposal::updateOrCreate(
                    ['academic_term_id' => $term->id, 'college' => $college],
                    [
                        'submitted_by' => $chair->id,
                        'section_plan_id' => $primaryPlan->id,
                        'status' => in_array($college, ['coa', 'cbae'], true) ? 'dean_approved' : 'draft',
                        'decided_by' => null,
                        'decided_at' => null,
                        'decision_reason' => null,
                    ],
                );
                AcademicTermCollegeWorkflow::updateOrCreate(
                    ['academic_term_id' => $term->id, 'college' => $college],
                    [
                        'stage' => AcademicTermCollegeWorkflowStage::ForDeanApproval,
                        'schedule_submitted_by' => $chair->id,
                        'schedule_submitted_at' => now(),
                    ],
                );
            }
        });
    }

    /**
     * A day/start-hour pair for the Nth subject in a block, cycling through
     * six school days before advancing the hour — six days × nine hourly
     * slots (8:00–17:00) covers every curriculum this seeder has seen
     * without ever producing an invalid or unrealistic class time.
     *
     * @return array{0: string, 1: int}
     */
    private function weekSlot(int $index): array
    {
        $days = ['M', 'T', 'W', 'Th', 'F', 'Sat'];

        return [$days[$index % count($days)], 8 + intdiv($index, count($days))];
    }

    /**
     * A college can have several active curricula (e.g. CCS: BSCS, BSIT,
     * and a raw catalog-import placeholder) — picking the newest by id is
     * arbitrary and can pick one no seeded student is actually enrolled in,
     * which would leave every seeded student's block pool empty for that
     * college. Prefer whichever active curriculum seeded students in this
     * college actually have, falling back to newest-by-id only when none do
     * (e.g. colleges DemoEnrollmentSeeder never touches).
     */
    private function activeCurriculumFor(string $college): ?Curriculum
    {
        $activeCurricula = Curriculum::query()
            ->whereHas('program', fn ($query) => $query->where('college', $college))
            ->where('status', 'active');

        $studentCurriculumIds = StudentProfile::query()
            ->whereHas('program', fn ($query) => $query->where('college', $college))
            ->pluck('curriculum_id');

        if ($studentCurriculumIds->isNotEmpty()) {
            $matched = (clone $activeCurricula)->whereIn('id', $studentCurriculumIds)->latest('id')->first();
            if ($matched !== null) {
                return $matched;
            }
        }

        return $activeCurricula->latest('id')->first();
    }

    private function guardEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('ProgramChairScheduleSampleSeeder may only run locally or in testing.');
        }
    }
}
