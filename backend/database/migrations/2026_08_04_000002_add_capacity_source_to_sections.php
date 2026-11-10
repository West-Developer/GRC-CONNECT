<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which authority owns a section's `capacity`: the year-level plan
 * (`plan`, the default) or a deliberate per-section override (`manual`).
 *
 * Without this column the two capacity controls cannot coexist.
 * `SaveSectionPlan::release()` re-runs on every "Add section" / "Remove
 * section" / "Generate subject list" click, so propagating the plan's
 * `students_per_block` to existing rows would silently overwrite whatever
 * the Chair typed into a single section's capacity field. `capacity_source`
 * lets release() update only the rows still tracking the plan and leave
 * overridden rows alone.
 *
 * A plain string rather than an enum column, matching how `sections.status`
 * and `sections.modality` are stored; `App\Domain\Organization\CapacitySource`
 * is the authority on the permitted values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->string('capacity_source')->default('plan')->after('capacity');
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropColumn('capacity_source');
        });
    }
};
