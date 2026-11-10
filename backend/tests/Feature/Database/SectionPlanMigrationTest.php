<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SectionPlanMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_section_planning_schema_supports_year_counts_and_schedule_modality(): void
    {
        $this->assertTrue(Schema::hasColumns('academic_term_section_plans', [
            'academic_term_id', 'curriculum_id', 'college', 'year_level',
            'section_count', 'status', 'submitted_at',
        ]));
        $this->assertTrue(Schema::hasColumn('sections', 'modality'));
        $this->assertTrue(Schema::hasColumn('sections', 'section_plan_id'));
        $this->assertTrue(Schema::hasColumn('schedule_proposals', 'college'));
    }
}
