<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable preference profiles are deliberately separate from the legacy
 * term-scoped table. Existing clients and records remain valid while new
 * faculty planning uses exact curriculum + semester context.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faculty_curriculum_subject_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('professor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('curriculum_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->string('semester', 16);
            $table->unsignedTinyInteger('rank');
            $table->string('origin', 32)->default('declared');
            $table->timestamps();

            $table->unique(
                ['professor_id', 'curriculum_id', 'semester', 'subject_id'],
                'fcsp_prof_cur_sem_sub_unique',
            );
            $table->unique(
                ['professor_id', 'curriculum_id', 'semester', 'rank'],
                'fcsp_prof_cur_sem_rank_unique',
            );
        });

        Schema::create('faculty_teaching_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('professor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('curriculum_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->string('semester', 16);
            $table->string('source_kind', 32)->default('workbook_reference');
            $table->string('source_workbook', 128);
            $table->string('raw_alias')->nullable();
            $table->unsignedSmallInteger('evidence_count')->default(1);
            $table->timestamps();

            $table->unique(
                ['professor_id', 'curriculum_id', 'semester', 'subject_id', 'source_kind'],
                'fth_prof_cur_sem_sub_src_unique',
            );
        });

        Schema::table('faculty_availabilities', function (Blueprint $table): void {
            $table->string('origin', 32)->default('declared')->after('ends_at_time');
            $table->index(['professor_id', 'origin'], 'faculty_avail_prof_origin_idx');
        });
    }

    public function down(): void
    {
        Schema::table('faculty_availabilities', function (Blueprint $table): void {
            $table->dropIndex('faculty_avail_prof_origin_idx');
            $table->dropColumn('origin');
        });

        Schema::dropIfExists('faculty_teaching_histories');
        Schema::dropIfExists('faculty_curriculum_subject_preferences');
    }
};
