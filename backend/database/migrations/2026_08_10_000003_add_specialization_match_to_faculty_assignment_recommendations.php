<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faculty_assignment_recommendations', function (Blueprint $table): void {
            $table->string('specialization_match', 16)->nullable()->after('preference_rank');
        });
    }

    public function down(): void
    {
        Schema::table('faculty_assignment_recommendations', function (Blueprint $table): void {
            $table->dropColumn('specialization_match');
        });
    }
};
