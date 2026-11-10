<?php

namespace App\Console\Commands;

use App\Actions\Analytics\DeriveSectionDemandObservations;
use App\Models\AcademicTerm;
use Illuminate\Console\Command;

/**
 * Manual/scheduled re-run of `DeriveSectionDemandObservations` — the same
 * aggregation `StudentRosterSeeder::run()` already triggers as its own last
 * step, exposed here for refreshing `section_demand_observations` after new
 * enrollments/grades land without reseeding the whole roster.
 */
final class DeriveSectionDemandObservationsCommand extends Command
{
    protected $signature = 'analytics:derive-demand-observations
        {--term= : Restrict aggregation to a single academic_term_id, instead of every term}';

    protected $description = "Aggregate real enrollment history into section_demand_observations rows (source='derived_from_enrollments'), replacing synthetic rows at the same key";

    public function handle(DeriveSectionDemandObservations $deriver): int
    {
        $term = null;
        $termOption = $this->option('term');

        // `is_scalar()` (not `is_string()`) because Laravel's test harness
        // (`$this->artisan(..., ['--term' => $id])`) passes an already-typed
        // int straight through, while a real CLI invocation always yields a
        // string — both must resolve the same way here.
        if (is_scalar($termOption) && (string) $termOption !== '') {
            $term = AcademicTerm::query()->find((int) $termOption);

            if ($term === null) {
                $this->error("No academic_term with id '{$termOption}' was found.");

                return self::FAILURE;
            }
        }

        $count = $deriver->execute($term);

        $this->info("Upserted {$count} section demand observation(s).");

        return self::SUCCESS;
    }
}
