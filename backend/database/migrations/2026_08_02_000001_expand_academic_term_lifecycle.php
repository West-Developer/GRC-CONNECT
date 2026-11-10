<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the two deadline columns Registrar Head term creation requires,
     * and remaps existing rows onto the expanded 13-stage status vocabulary
     * (see App\Domain\Organization\AcademicTermStatus) — a data-mutating
     * migration is the mechanism that enum's own docblock anticipates for
     * exactly this kind of vocabulary replacement.
     */
    public function up(): void
    {
        Schema::table('academic_terms', function (Blueprint $table) {
            $table->dateTime('add_drop_deadline_at')->nullable()->after('enrollment_closes_at');
            $table->dateTime('grading_deadline_at')->nullable()->after('add_drop_deadline_at');
        });

        DB::table('academic_terms')->where('status', 'planning')->update(['status' => 'draft']);
        DB::table('academic_terms')->where('status', 'active')->update(['status' => 'semester_ongoing']);
        DB::table('academic_terms')->where('status', 'closed')->update(['status' => 'semester_closed']);
    }

    public function down(): void
    {
        DB::table('academic_terms')->where('status', 'draft')->update(['status' => 'planning']);
        DB::table('academic_terms')->where('status', 'semester_ongoing')->update(['status' => 'active']);
        DB::table('academic_terms')->where('status', 'semester_closed')->update(['status' => 'closed']);

        Schema::table('academic_terms', function (Blueprint $table) {
            $table->dropColumn(['add_drop_deadline_at', 'grading_deadline_at']);
        });
    }
};
