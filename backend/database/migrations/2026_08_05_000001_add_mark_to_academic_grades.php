<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `final_grade` (DECIMAL) alone cannot represent a non-numeric mark — it
 * must be NULL for `C`/`NC`/`INC`/`DRP`, which makes a NULL `final_grade`
 * ambiguous across five different meanings (not yet encoded, or one of the
 * four non-numeric marks). `mark` resolves that: a plain nullable string
 * (not a DB enum) carrying one `App\Domain\Academic\GradeMark` value,
 * matching how `sections.status`/`sections.modality` are stored elsewhere in
 * this schema — the grading scale is an open PRD §17 decision, and a DB enum
 * would need a migration for every amendment.
 *
 * `final_grade` is kept as a derived mirror maintained by the write actions
 * (numeric mark -> copy; non-numeric mark -> NULL), so every existing reader
 * (`BuildEligibleSubjectPool`, reports) keeps working unchanged. The API
 * stops *accepting* `final_grade` from this migration's release forward; it
 * only ever emits it.
 *
 * The backfill below copies any existing on-scale `final_grade` into `mark`.
 * A legacy off-scale value (e.g. `2.10`, not on the approved scale) is left
 * with `mark = NULL` rather than silently coerced to the nearest scale
 * value — it surfaces as an "off-scale" row instead.
 *
 * The status index is new because the Registrar's grade-lock queue
 * (`WHERE status = 'submitted'`) had no supporting index — only the
 * composite unique key leading with `student_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_grades', function (Blueprint $table) {
            $table->string('mark', 8)->nullable()->after('final_grade');
            $table->index('status', 'academic_grades_status_index');
        });

        $onScaleValues = ['1.00', '1.25', '1.50', '1.75', '2.00', '2.25', '2.50', '2.75', '3.00', '5.00'];

        DB::table('academic_grades')
            ->whereIn('final_grade', $onScaleValues)
            ->update(['mark' => DB::raw('final_grade')]);
    }

    public function down(): void
    {
        Schema::table('academic_grades', function (Blueprint $table) {
            $table->dropIndex('academic_grades_status_index');
            $table->dropColumn('mark');
        });
    }
};
