<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('section_demand_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prediction_run_id')->constrained('prediction_runs')->restrictOnDelete();
            $table->foreignId('academic_term_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->decimal('predicted_demand', 10, 2);
            $table->unsignedSmallInteger('suggested_section_count');
            $table->decimal('confidence_lower', 10, 2)->nullable();
            $table->decimal('confidence_upper', 10, 2)->nullable();
            $table->timestamps();

            $table->unique(
                ['prediction_run_id', 'academic_term_id', 'subject_id'],
                'section_demand_forecasts_run_term_subject_unique',
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE section_demand_forecasts
            ADD CONSTRAINT section_forecasts_demand_nonnegative CHECK (predicted_demand >= 0),
            ADD CONSTRAINT section_forecasts_lower_nonnegative CHECK (confidence_lower IS NULL OR confidence_lower >= 0),
            ADD CONSTRAINT section_forecasts_upper_nonnegative CHECK (confidence_upper IS NULL OR confidence_upper >= 0),
            ADD CONSTRAINT section_forecasts_bounds_ordered CHECK (
              confidence_lower IS NULL OR confidence_upper IS NULL OR confidence_upper >= confidence_lower
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('section_demand_forecasts');
    }
};
