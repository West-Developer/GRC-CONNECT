<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Program Head may leave a free-text note for the student alongside their
 * approve/reject decision on the Program Head approval stage (e.g. why a
 * subject was swapped, or what to take instead). Null means no comment was
 * left, the behaviour every existing enrollment already has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->text('program_head_comment')->nullable()->after('requires_overload_approval');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->dropColumn('program_head_comment');
        });
    }
};
