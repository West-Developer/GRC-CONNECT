<?php

namespace Database\Seeders;

use App\Domain\Analytics\HistoricalCohortResolver;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use App\Models\CurriculumSubject;
use App\Models\FacultyAvailability;
use App\Models\FacultySubjectPreference;
use App\Models\RoomCatalogEntry;
use App\Models\SectionDemandObservation;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use RuntimeException;

/** Synthetic local/test inputs so predictive planning has no personal data dependency. */
final class PredictivePlanningInputSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('PredictivePlanningInputSeeder may only run locally or in testing.');
        }
        $term = AcademicTerm::query()->whereNotIn('status', ['archived', 'semester_closed'])->latest('id')->first();
        if ($term === null) {
            return;
        }
        $faculty = User::query()
            ->where('role', UserRole::Faculty->value)
            ->where('status', UserStatus::Active->value)
            ->where('college', 'ccs')
            ->orderBy('id')
            ->get();
        $termPlacements = CurriculumSubject::query()
            ->with('curriculum.program')
            ->where(function ($query) use ($term): void {
                $query->where('semester', $term->semester)->orWhere('semester', 'like', '%'.$term->semester.'%');
            })
            ->whereHas('curriculum.program', fn ($programs) => $programs->where('college', 'ccs'))
            ->orderBy('subject_id')
            ->get();
        $subjects = $termPlacements->unique('subject_id')->values();
        if ($faculty->isEmpty()) {
            return;
        }
        foreach ($faculty as $member) {
            foreach (range(1, 6) as $day) {
                FacultyAvailability::updateOrCreate(
                    ['professor_id' => $member->id, 'day_of_week' => $day, 'starts_at_time' => '08:00:00'],
                    ['ends_at_time' => '17:00:00'],
                );
            }
        }
        $this->seedReferenceScheduleInputs($termPlacements->values());
        foreach ($subjects as $index => $placement) {
            foreach (range(0, min(3, $faculty->count()) - 1) as $offset) {
                $member = $faculty[($index + $offset) % $faculty->count()];
                FacultySubjectPreference::updateOrCreate(
                    ['professor_id' => $member->id, 'academic_term_id' => $term->id, 'subject_id' => $placement->subject_id],
                    ['rank' => $offset + 1],
                );
            }
        }
        Subject::query()->whereIn('id', $subjects->pluck('subject_id'))->each(function (Subject $subject): void {
            if ($subject->room_requirement === null) {
                $subject->update([
                    'room_requirement' => str_contains(strtoupper($subject->title), 'LAB')
                        ? 'laboratory'
                        : 'lecture',
                ]);
            }
        });
        RoomCatalogEntry::query()->where('college', 'ccs')->each(function (RoomCatalogEntry $room): void {
            $room->update([
                'capacity' => max($room->capacity ?? 0, 45),
                'room_type' => $room->room_type ?? (str_contains(strtoupper($room->name), 'LAB') ? 'laboratory' : 'lecture'),
            ]);
        });
        $this->seedHistoricalDemandForCurrentTerm($term, $termPlacements);
    }

    /** @param Collection<int, CurriculumSubject> $placements */
    private function seedReferenceScheduleInputs(Collection $placements): void
    {
        $days = ['M', 'T', 'W', 'Th', 'F'];
        $times = [
            ['08:00:00', '10:00:00'],
            ['10:00:00', '12:00:00'],
            ['13:00:00', '15:00:00'],
            ['15:00:00', '17:00:00'],
        ];
        foreach ($placements as $index => $placement) {
            if ($placement->reference_day !== null && $placement->reference_start_time !== null && $placement->reference_end_time !== null && $placement->reference_modality !== null) {
                continue;
            }
            [$start, $end] = $times[intdiv($index, count($days)) % count($times)];
            $placement->update([
                'reference_day' => $placement->reference_day ?? $days[$index % count($days)],
                'reference_start_time' => $placement->reference_start_time ?? $start,
                'reference_end_time' => $placement->reference_end_time ?? $end,
                'reference_modality' => $placement->reference_modality ?? match ($index % 3) {
                    1 => 'hyflex_a',
                    2 => 'hyflex_b',
                    default => 'f2f',
                },
            ]);
        }
    }

    /** @param Collection<int, CurriculumSubject> $placements */
    private function seedHistoricalDemandForCurrentTerm(AcademicTerm $term, Collection $placements): void
    {
        $resolver = app(HistoricalCohortResolver::class);
        foreach ($placements as $placement) {
            $history = $resolver->resolve($term->school_year, $term->semester, $placement->year_level);
            $historicalTerm = AcademicTerm::firstOrCreate(
                ['school_year' => $history->schoolYear, 'semester' => $history->semester],
                ['status' => AcademicTermStatus::Archived],
            );
            $base = 32 + (($placement->subject_id * 7 + $history->yearLevel * 5) % 28);
            $sectionCount = max(1, (int) ceil($base / 40));
            SectionDemandObservation::updateOrCreate(
                [
                    'academic_term_id' => $historicalTerm->id,
                    'program_id' => $placement->curriculum->program_id,
                    'curriculum_id' => $placement->curriculum_id,
                    'subject_id' => $placement->subject_id,
                    'year_level' => $history->yearLevel,
                ],
                [
                    'college' => 'ccs',
                    'cohort_size' => $base + 3,
                    'enrolled_count' => $base,
                    'section_count' => $sectionCount,
                    'offered_capacity' => $sectionCount * 40,
                    'source' => 'local_synthetic_planning_input',
                ],
            );
        }
    }
}
