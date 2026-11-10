<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Runs the full synthetic development dataset.
 *
 * Order is a genuine dependency chain, not a preference: curricula need
 * programs and subjects, sections need subjects/terms/faculty, and the demo
 * enrollments need all of the above. Every seeder is individually idempotent,
 * so re-running this is safe.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleUserSeeder::class,
            FeeScheduleSeeder::class,
            QueueKioskSeeder::class,
            // Additive: one Program Chair per supported college, alongside
            // (not replacing) RoleUserSeeder's single generic chair.
            CollegeProgramChairSeeder::class,
            ProgramSeeder::class,
            AcademicTermSeeder::class,
            SubjectSeeder::class,
            // The real GRC catalog: 409 subjects across the 12 real
            // programs (see ProgramSeeder), 3 curriculum versions each,
            // the 74 confirmed prerequisites, and the active version's real
            // per-subject schedule/faculty reference data. See each
            // seeder's docblock.
            GrcSubjectCatalogSeeder::class,
            GrcOlderSubjectCatalogSeeder::class,
            // LEC/LAB companions, linked once every catalog subject exists.
            SubjectPairingSeeder::class,
            GrcCurriculumSeeder::class,
            GrcPrerequisiteSeeder::class,
            GrcCurriculumScheduleReferenceSeeder::class,
            RoomCatalogSeeder::class,
            CatalogFacultySeeder::class,
            WorkbookFacultyProfileSeeder::class,
            SectionSeeder::class,
            DemoEnrollmentSeeder::class,
            // Synthetic section_demand_observations fallback data, seeded
            // BEFORE StudentRosterSeeder on purpose (Task 5 of the
            // student-accounts-and-academic-history-seed plan):
            // StudentRosterSeeder's own final step aggregates real
            // enrollment history into that same table
            // (`source = 'derived_from_enrollments'`), upserting over any
            // synthetic row at a shared key. That only replaces synthetic
            // with real if the synthetic rows are already there when
            // StudentRosterSeeder runs — reversing this order would let
            // these synthetic seeders overwrite the real derived rows
            // instead, silently making the roster's real students invisible
            // to the forecaster again.
            SectionDemandObservationSeeder::class,
        ]);

        // Keep normal test fixtures lean. PredictivePlanningInputSeeder is
        // still test-safe when invoked explicitly, while local development
        // receives the synthetic planning inputs needed for a live smoke
        // run — also before StudentRosterSeeder, for the same
        // upsert-ordering reason as SectionDemandObservationSeeder above.
        if (app()->environment('local')) {
            $this->call(PredictivePlanningInputSeeder::class);
        }

        $this->call([
            ProgramChairScheduleSampleSeeder::class,
            StudentRosterSeeder::class,
        ]);
    }
}
