<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a student is an incoming Freshman or a Transferee — informational
 * only, set at admission provisioning. Nullable: existing/seeded students
 * predate this field. See `App\Domain\Identity\StudentType`'s docblock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->string('student_type')->nullable()->after('enrollment_category');
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn('student_type');
        });
    }
};
