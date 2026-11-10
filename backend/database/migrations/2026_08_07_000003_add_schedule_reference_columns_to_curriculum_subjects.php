<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A placement's "typical" real-world schedule and instructor, extracted from
 * the representative block of GRC's real Excel schedules (see
 * `data/extract-curriculum-schedule-references.py`). Deliberately kept on
 * the placement row itself, not a new table -- the relationship is exactly
 * 1:1 -- and deliberately NOT tied to any academic_term_id: a curriculum's
 * typical schedule doesn't depend on which term happens to be currently
 * open, so this data has no dependency on term-lifecycle state.
 *
 * Reference/display data only. `reference_professor_name` is a plain string,
 * not a User foreign key -- resolving it into a real Faculty account only
 * happens on demand, when a Program Chair actually uses it (see
 * AutoAssignSectionScheduleReferences), never automatically here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curriculum_subjects', function (Blueprint $table) {
            $table->string('reference_day')->nullable()->after('is_required');
            $table->time('reference_start_time')->nullable()->after('reference_day');
            $table->time('reference_end_time')->nullable()->after('reference_start_time');
            $table->string('reference_room')->nullable()->after('reference_end_time');
            $table->string('reference_modality')->nullable()->after('reference_room');
            $table->string('reference_professor_name')->nullable()->after('reference_modality');
            $table->string('reference_sched_id')->nullable()->after('reference_professor_name');
            $table->text('reference_notes')->nullable()->after('reference_sched_id');
        });
    }

    public function down(): void
    {
        Schema::table('curriculum_subjects', function (Blueprint $table) {
            $table->dropColumn([
                'reference_day', 'reference_start_time', 'reference_end_time',
                'reference_room', 'reference_modality', 'reference_professor_name',
                'reference_sched_id', 'reference_notes',
            ]);
        });
    }
};
