<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The year a student first entered their program -- what
 * `App\Domain\Curriculum\CurriculumVersion::resolveForEntryYear()` matches
 * against a program's `curricula.effective_start_year`/`effective_end_year`
 * range to decide which curriculum version they follow. Nullable: existing
 * seeded/imported students may not have a known entry year, and nothing yet
 * requires one at admission time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->unsignedSmallInteger('entry_year')->nullable()->after('curriculum_id');
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->dropColumn('entry_year');
        });
    }
};
