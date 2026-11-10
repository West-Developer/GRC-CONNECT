<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §5.2/§10.3. `subjects.units` and `enrollments.total_units` were
 * originally integer columns, which cannot represent the real GRC catalog:
 * Leadership subjects (LEAD 1, LEAD 2, ...) carry 1.5 units. Widening to
 * `decimal(4,1)`/`decimal(6,1)` is purely additive precision — every existing
 * whole-number value round-trips unchanged, so no data is lost or altered.
 *
 * Cast as `float` in the Eloquent models (not left as a raw decimal string,
 * unlike `academic_grades.final_grade`) because a unit count is not a §17
 * policy-sensitive value; it is an ordinary numeric quantity summed for
 * `enrollments.total_units` and displayed directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->decimal('units', 4, 1)->change();
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->decimal('total_units', 6, 1)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->unsignedTinyInteger('units')->change();
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->unsignedSmallInteger('total_units')->default(0)->change();
        });
    }
};
