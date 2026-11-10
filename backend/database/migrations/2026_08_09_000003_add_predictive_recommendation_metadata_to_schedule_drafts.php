<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_term_section_plans', function (Blueprint $table): void {
            $table->string('recommendation_source', 32)->nullable()->after('students_per_block');
            $table->unsignedSmallInteger('recommended_section_count')->nullable()->after('recommendation_source');
            $table->boolean('recommendation_is_overridden')->default(false)->after('recommended_section_count');
            $table->foreignId('recommendation_prediction_run_id')
                ->nullable()
                ->after('recommendation_is_overridden');
            $table->foreign('recommendation_prediction_run_id', 'section_plans_reco_run_fk')
                ->references('id')
                ->on('prediction_runs')
                ->nullOnDelete();
        });

        Schema::table('sections', function (Blueprint $table): void {
            $table->string('recommendation_source', 32)->nullable()->after('capacity_source');
            $table->unsignedSmallInteger('recommended_capacity')->nullable()->after('recommendation_source');
            $table->foreignId('recommendation_prediction_run_id')
                ->nullable()
                ->after('recommended_capacity');
            $table->foreign('recommendation_prediction_run_id', 'sections_reco_run_fk')
                ->references('id')
                ->on('prediction_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table): void {
            $table->dropForeign('sections_reco_run_fk');
            $table->dropColumn('recommendation_prediction_run_id');
            $table->dropColumn(['recommendation_source', 'recommended_capacity']);
        });

        Schema::table('academic_term_section_plans', function (Blueprint $table): void {
            $table->dropForeign('section_plans_reco_run_fk');
            $table->dropColumn('recommendation_prediction_run_id');
            $table->dropColumn(['recommendation_source', 'recommended_section_count', 'recommendation_is_overridden']);
        });
    }
};
