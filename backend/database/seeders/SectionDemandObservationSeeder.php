<?php

namespace Database\Seeders;

use App\Models\AcademicTerm;
use App\Models\CurriculumSubject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Local/test-only aggregate demand history.
 *
 * Values are synthetic and de-identified: no learner row or student number is
 * read or produced. The stable variation gives the predictive service usable
 * historical cohort, enrollment, capacity and section-count examples until
 * approved institutional aggregates are imported.
 */
final class SectionDemandObservationSeeder extends Seeder
{
    public function run(): void
    {
        $this->guardEnvironment();

        $terms = AcademicTerm::query()->orderBy('school_year')->orderBy('semester')->get();
        $placements = CurriculumSubject::query()
            ->with('curriculum.program')
            ->whereHas('curriculum.program', fn ($programs) => $programs->whereNotNull('college'))
            ->get();
        $now = now();
        $rows = [];

        foreach ($placements as $placement) {
            $program = $placement->curriculum->program;
            if ($program->college === null) {
                continue;
            }

            foreach ($terms as $termIndex => $term) {
                foreach (range(1, 4) as $yearLevel) {
                    $base = 30 + (($placement->subject_id * 7 + $yearLevel * 5 + $termIndex * 3) % 36);
                    $sections = max(1, (int) ceil($base / 40));
                    $rows[] = [
                        'academic_term_id' => $term->id,
                        'program_id' => $program->id,
                        'curriculum_id' => $placement->curriculum_id,
                        'subject_id' => $placement->subject_id,
                        'college' => $program->college->value,
                        'year_level' => $yearLevel,
                        'cohort_size' => $base + 3,
                        'enrolled_count' => $base,
                        'section_count' => $sections,
                        'offered_capacity' => $sections * 40,
                        'source' => 'local_synthetic_aggregate',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('section_demand_observations')->upsert(
                $chunk,
                ['academic_term_id', 'program_id', 'curriculum_id', 'subject_id', 'year_level'],
                ['college', 'cohort_size', 'enrolled_count', 'section_count', 'offered_capacity', 'source', 'updated_at'],
            );
        }
    }

    private function guardEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('SectionDemandObservationSeeder may only run locally or in testing.');
        }
    }
}
