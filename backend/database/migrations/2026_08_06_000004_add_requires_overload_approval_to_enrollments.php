<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §17 FR-ENR-004: maximum regular units and the overload approval
 * workflow. `App\Domain\Enrollment\OverloadEvaluator` flags a submission
 * this way when it exceeds `config('enrollment.max_regular_units')` but
 * stays within `config('enrollment.overload_max_units')` — both default
 * `null`, so this column defaults `false` and nothing changes until GRC
 * sets a value for either. Registrar Staff must acknowledge the flag
 * (`overload_acknowledged: true`) before `registrar_approve` succeeds — see
 * `UpdateEnrollmentRequest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->boolean('requires_overload_approval')->default(false)->after('total_units');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn('requires_overload_approval');
        });
    }
};
