<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RollsBackThroughMigration;
use Tests\TestCase;

final class CurriculumScheduleReferenceMigrationTest extends TestCase
{
    use RefreshDatabase;
    use RollsBackThroughMigration;

    public function test_curriculum_subjects_gains_the_reference_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('curriculum_subjects', [
            'reference_day', 'reference_start_time', 'reference_end_time',
            'reference_room', 'reference_modality', 'reference_professor_name',
            'reference_sched_id', 'reference_notes',
        ]));
    }

    public function test_migrations_are_fully_reversible(): void
    {
        // Six later migrations currently follow the reference migration, so
        // the seventh rollback reaches the reference migration itself.
        // Roll back through this one rather than only rolling back the latest
        // unrelated scheduling migration.
        $this->rollbackThrough('2026_08_07_000003_add_schedule_reference_columns_to_curriculum_subjects');

        $this->assertFalse(Schema::hasColumn('curriculum_subjects', 'reference_day'));

        $this->artisan('migrate')->assertExitCode(0);

        $this->assertTrue(Schema::hasColumn('curriculum_subjects', 'reference_day'));
    }
}
