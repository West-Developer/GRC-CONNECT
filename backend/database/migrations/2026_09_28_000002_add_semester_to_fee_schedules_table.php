<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A fee can now be scoped to a specific semester ('1st' or '2nd'). Null means "every semester", the
 * exact behaviour every existing fee already had, so this backfills nothing and changes no assessment
 * until an Accounting Staff member actually narrows a fee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_schedules', function (Blueprint $table): void {
            $table->string('semester', 3)->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('fee_schedules', function (Blueprint $table): void {
            $table->dropColumn('semester');
        });
    }
};
