<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('section_demand_forecasts', function (Blueprint $table): void {
            // The original unique index is also the supporting index MySQL
            // selected for prediction_run_id's foreign key, so retain a
            // dedicated index before replacing that constraint.
            $table->index('prediction_run_id', 'section_demand_forecasts_prediction_run_index');
            $table->dropUnique('section_demand_forecasts_run_term_subject_unique');
            $table->unique(
                ['prediction_run_id', 'academic_term_id', 'curriculum_id', 'subject_id', 'year_level'],
                'section_demand_forecasts_cohort_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('section_demand_forecasts', function (Blueprint $table): void {
            $table->dropUnique('section_demand_forecasts_cohort_unique');
            $table->unique(
                ['prediction_run_id', 'academic_term_id', 'subject_id'],
                'section_demand_forecasts_run_term_subject_unique',
            );
            $table->dropIndex('section_demand_forecasts_prediction_run_index');
        });
    }
};
