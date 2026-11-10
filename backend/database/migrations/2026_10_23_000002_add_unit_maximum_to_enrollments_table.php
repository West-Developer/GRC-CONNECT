<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The unit maximum that applied to the student when they submitted (their curriculum's, or the configured one).
 *
 * An irregular student may now submit a load over that maximum after confirming they are sure (the Program Chair
 * then sees it and sets the right units and schedule), so the enrollment keeps the maximum it was measured
 * against: "over the maximum" is `total_units > unit_maximum`, and stays true to what the student saw even if
 * the curriculum's maximum changes later. Null when no maximum applied. Existing rows stay null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->decimal('unit_maximum', 5, 1)->nullable()->after('requires_overload_approval');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->dropColumn('unit_maximum');
        });
    }
};
