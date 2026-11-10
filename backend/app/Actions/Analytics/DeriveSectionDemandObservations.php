<?php

namespace App\Actions\Analytics;

use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Models\AcademicTerm;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates real enrollment history (`enrollment_subjects` joined to
 * `sections`, `academic_term_section_plans`, `curricula`, `programs`, and
 * `enrollments`) into `section_demand_observations` rows with
 * `source = 'derived_from_enrollments'`, upserting on the table's real
 * unique key (`academic_term_id`, `program_id`, `curriculum_id`,
 * `subject_id`, `year_level`) so a derived row replaces any synthetic seed
 * row occupying the same key — see `SectionDemandObservationSeeder`
 * (`source = 'local_synthetic_aggregate'`) and `PredictivePlanningInputSeeder`
 * (`source = 'local_synthetic_planning_input'`) for the rows this can
 * supersede. A key with no matching real enrollment history is never
 * touched — its synthetic fallback row, if one exists, survives untouched.
 *
 * **`year_level` is read from `academic_term_section_plans.year_level` via
 * `sections.section_plan_id`, NOT from `student_profiles.year_level`.**
 * (Corrected after task review — the first version of this class used the
 * enrolled student's *current* year level, which is only correct for a
 * student's most recent term; for every earlier historical term it silently
 * disagreed with the year level the section was actually planned/offered
 * at.) `StudentRosterSeeder::seedSectionHistory()` computes the real
 * per-term projected year level for each historical cohort
 * (`$yearLevel = $cohort['year_level'] - $academicYearsElapsed`) and stores
 * it on the `academic_term_section_plans` row each of that term's sections
 * points to via `section_plan_id` — that is the authoritative "what year
 * level was this section planned/offered at" value, and it is also exactly
 * what `GenerateSectionDemandForecasts` queries `SectionDemandObservation`
 * by (via `HistoricalCohortResolver::resolve()`, which walks a *target*
 * placement's year level backwards the same way, then looks up observations
 * at that resolved year level — never at a student's current standing).
 * `program_id`/`curriculum_id` are likewise read from the plan's own
 * `curriculum_id` (and that curriculum's `program_id`) rather than the
 * enrolled student's own profile fields — a student's declared program/
 * curriculum doesn't drift across terms the way their year level does, but
 * sourcing every dimension from the section's own plan keeps this
 * consistently tied to "what was actually offered", not "who happened to
 * enroll in it". `student_profiles` is therefore not joined here at all.
 */
final class DeriveSectionDemandObservations
{
    private const SOURCE = 'derived_from_enrollments';

    private const UPSERT_CHUNK_SIZE = 500;

    public function execute(?AcademicTerm $term = null): int
    {
        $sectionLevelRows = $this->sectionLevelQuery($term)->get();

        if ($sectionLevelRows->isEmpty()) {
            return 0;
        }

        // Second-level grouping (per key, across that key's distinct
        // sections) happens here in PHP rather than as a SQL subquery over
        // a subquery: `$sectionLevelRows` is already one row per (key,
        // section) — at most a few thousand rows even at the full
        // 3,210-student roster's scale — so summing/counting in memory
        // avoids a second round trip without meaningfully costing anything.
        /** @var array<string, array{academic_term_id: int, program_id: int, curriculum_id: int, subject_id: int, college: string, year_level: int, section_count: int, offered_capacity: int, enrolled_count: int}> $grouped */
        $grouped = [];
        foreach ($sectionLevelRows as $row) {
            $key = implode('|', [
                $row->academic_term_id, $row->program_id, $row->curriculum_id,
                $row->subject_id, $row->college, $row->year_level,
            ]);

            $grouped[$key] ??= [
                'academic_term_id' => (int) $row->academic_term_id,
                'program_id' => (int) $row->program_id,
                'curriculum_id' => (int) $row->curriculum_id,
                'subject_id' => (int) $row->subject_id,
                'college' => (string) $row->college,
                'year_level' => (int) $row->year_level,
                'section_count' => 0,
                'offered_capacity' => 0,
                'enrolled_count' => 0,
            ];

            $grouped[$key]['section_count']++;
            $grouped[$key]['offered_capacity'] += (int) $row->section_capacity;
            $grouped[$key]['enrolled_count'] += (int) $row->section_enrolled;
        }

        $cohortSizes = $this->cohortSizes($term);
        $now = now();

        $upsertRows = [];
        foreach ($grouped as $row) {
            $cohortKey = $row['academic_term_id'].'|'.$row['program_id'].'|'.$row['year_level'];
            $upsertRows[] = array_merge($row, [
                // Falls back to this row's own enrolled_count when no
                // program+year_level cohort headcount was found (never
                // happens in practice — the same enrollments that produced
                // this subject row also produced the cohort's headcount —
                // but keeps the not-null `cohort_size` column honest rather
                // than writing a 0 no real data backs).
                'cohort_size' => $cohortSizes[$cohortKey] ?? $row['enrolled_count'],
                'source' => self::SOURCE,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (array_chunk($upsertRows, self::UPSERT_CHUNK_SIZE) as $chunk) {
            DB::table('section_demand_observations')->upsert(
                $chunk,
                ['academic_term_id', 'program_id', 'curriculum_id', 'subject_id', 'year_level'],
                ['college', 'cohort_size', 'enrolled_count', 'section_count', 'offered_capacity', 'source', 'updated_at'],
            );
        }

        return count($upsertRows);
    }

    /**
     * One row per (key, section): the finest grain the aggregation needs.
     * `MAX(sec.capacity)` (rather than grouping by it directly) keeps this
     * portable under `ONLY_FULL_GROUP_BY` — `sec.capacity` is functionally
     * dependent on `sec.id`, which the query already groups by, but MySQL/
     * MariaDB only recognizes that exemption for a table's own primary key,
     * not a query-builder-composed `GROUP BY` list.
     *
     * Driven off `sections`, NOT `enrollment_subjects` — a section with zero
     * non-dropped enrollments must still contribute to `section_count`/
     * `offered_capacity` (this is "capacity actually offered", the correct
     * denominator for utilization-based forecasting, not "capacity of
     * sections that had at least one student"). The enrollment/enrollments
     * side is therefore `leftJoin`ed, with the "not dropped" / "not
     * terminal" filters moved onto the join's own `ON` clause instead of a
     * top-level `where()` — a `where()` there would silently turn the left
     * join back into an inner join by rejecting every row whose `es`/`e`
     * columns came back NULL, which is exactly the zero-enrollment case this
     * is meant to keep. `COUNT(DISTINCT e.student_id)` naturally ignores
     * those NULLs, so a section with no live enrollment contributes 0 to
     * `enrolled_count` while still counting itself and its capacity.
     *
     * A section with no `section_plan_id` (nullable — e.g. a section built
     * outside `StudentRosterSeeder::seedSectionHistory()`) is excluded by
     * the inner join to `academic_term_section_plans`: there is nowhere
     * else to read its year level from, and the column is `NOT NULL`, so
     * fabricating one would be worse than not producing a row at all.
     */
    private function sectionLevelQuery(?AcademicTerm $term): Builder
    {
        return DB::table('sections as sec')
            ->join('academic_term_section_plans as atsp', 'atsp.id', '=', 'sec.section_plan_id')
            ->join('curricula as c', 'c.id', '=', 'atsp.curriculum_id')
            ->join('programs as p', 'p.id', '=', 'c.program_id')
            ->leftJoin('enrollment_subjects as es', function ($join): void {
                $join->on('es.section_id', '=', 'sec.id')
                    ->where('es.status', '!=', EnrollmentSubjectStatus::Dropped->value);
            })
            ->leftJoin('enrollments as e', function ($join): void {
                $join->on('e.id', '=', 'es.enrollment_id')
                    ->whereNotIn('e.status', EnrollmentStatus::terminalValues());
            })
            ->whereNotNull('p.college')
            ->when($term?->id, fn ($query, int $termId) => $query->where('sec.academic_term_id', $termId))
            ->groupBy(['sec.academic_term_id', 'c.program_id', 'atsp.curriculum_id', 'sec.subject_id', 'p.college', 'atsp.year_level', 'sec.id'])
            ->select([
                'sec.academic_term_id as academic_term_id',
                'c.program_id as program_id',
                'atsp.curriculum_id as curriculum_id',
                'sec.subject_id as subject_id',
                'p.college as college',
                'atsp.year_level as year_level',
                DB::raw('MAX(sec.capacity) as section_capacity'),
                DB::raw('COUNT(DISTINCT e.student_id) as section_enrolled'),
            ]);
    }

    /**
     * Distinct-student headcount per (academic_term_id, program_id,
     * year_level) — independent of subject/curriculum/section, unlike
     * `enrolled_count`. This is `cohort_size`'s denominator: "how many
     * students at this program and year level are enrolled this term",
     * regardless of which of that term's subjects they took. Joined through
     * the same `sections` -> `academic_term_section_plans` -> `curricula`
     * chain as `sectionLevelQuery()` (rather than a simpler
     * `enrollments`/`student_profiles`-only query) for the same reason:
     * `year_level` has to be the historically-correct plan value, and that
     * only exists on the plan a student's enrolled sections point to, not
     * on their own profile.
     *
     * @return array<string, int>
     */
    private function cohortSizes(?AcademicTerm $term): array
    {
        $rows = DB::table('enrollment_subjects as es')
            ->join('sections as sec', 'sec.id', '=', 'es.section_id')
            ->join('academic_term_section_plans as atsp', 'atsp.id', '=', 'sec.section_plan_id')
            ->join('curricula as c', 'c.id', '=', 'atsp.curriculum_id')
            ->join('enrollments as e', 'e.id', '=', 'es.enrollment_id')
            ->where('es.status', '!=', EnrollmentSubjectStatus::Dropped->value)
            ->whereNotIn('e.status', EnrollmentStatus::terminalValues())
            ->when($term?->id, fn ($query, int $termId) => $query->where('sec.academic_term_id', $termId))
            ->groupBy(['sec.academic_term_id', 'c.program_id', 'atsp.year_level'])
            ->select([
                'sec.academic_term_id as academic_term_id',
                'c.program_id as program_id',
                'atsp.year_level as year_level',
                DB::raw('COUNT(DISTINCT e.student_id) as cohort_size'),
            ])
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->academic_term_id.'|'.$row->program_id.'|'.$row->year_level] = (int) $row->cohort_size;
        }

        return $map;
    }
}
