<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faculty_load_thresholds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academic_term_id')->constrained()->cascadeOnDelete();
            $table->string('college');
            $table->decimal('max_units', 6, 2);
            $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['academic_term_id', 'college'], 'faculty_load_threshold_term_college_unique');
        });

        Schema::create('faculty_assignment_recommendations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('schedule_generation_run_id');
            $table->unsignedBigInteger('section_id');
            $table->unsignedBigInteger('recommended_professor_id')->nullable();
            $table->unsignedSmallInteger('preference_rank')->nullable();
            $table->boolean('availability_match')->default(false);
            $table->boolean('conflict_free')->default(false);
            $table->json('rationale')->nullable();
            $table->timestamps();
            $table->foreign('schedule_generation_run_id', 'faculty_recommendation_run_fk')
                ->references('id')->on('schedule_generation_runs')->cascadeOnDelete();
            $table->foreign('section_id', 'faculty_recommendation_section_fk')
                ->references('id')->on('sections')->cascadeOnDelete();
            $table->foreign('recommended_professor_id', 'faculty_recommendation_professor_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->unique(['schedule_generation_run_id', 'section_id'], 'faculty_recommendation_run_section_unique');
        });

        Schema::table('section_demand_forecasts', function (Blueprint $table): void {
            $table->foreignId('program_id')->nullable()->after('academic_term_id')->constrained()->nullOnDelete();
            $table->foreignId('curriculum_id')->nullable()->after('program_id')->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('year_level')->nullable()->after('subject_id');
            $table->string('historical_school_year')->nullable()->after('year_level');
            $table->string('historical_semester')->nullable()->after('historical_school_year');
            $table->unsignedTinyInteger('historical_year_level')->nullable()->after('historical_semester');
            $table->json('rationale')->nullable()->after('confidence_upper');
        });

        Schema::table('sections', function (Blueprint $table): void {
            $table->text('manual_override_reason')->nullable()->after('recommendation_prediction_run_id');
        });

        Schema::table('room_catalog_entries', function (Blueprint $table): void {
            $table->unsignedSmallInteger('capacity')->nullable()->after('college');
            $table->string('room_type', 32)->nullable()->after('capacity');
        });

        Schema::table('subjects', function (Blueprint $table): void {
            $table->string('room_requirement', 32)->nullable()->after('units');
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table): void {
            $table->dropColumn('room_requirement');
        });
        Schema::table('room_catalog_entries', function (Blueprint $table): void {
            $table->dropColumn(['capacity', 'room_type']);
        });
        Schema::table('sections', function (Blueprint $table): void {
            $table->dropColumn('manual_override_reason');
        });
        Schema::table('section_demand_forecasts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('curriculum_id');
            $table->dropConstrainedForeignId('program_id');
            $table->dropColumn(['year_level', 'historical_school_year', 'historical_semester', 'historical_year_level', 'rationale']);
        });
        Schema::dropIfExists('faculty_assignment_recommendations');
        Schema::dropIfExists('faculty_load_thresholds');
    }
};
