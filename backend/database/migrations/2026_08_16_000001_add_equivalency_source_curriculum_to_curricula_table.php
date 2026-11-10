<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curricula', function (Blueprint $table): void {
            $table->foreignId('equivalency_source_curriculum_id')
                ->nullable()
                ->after('program_id')
                ->constrained('curricula')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('curricula', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('equivalency_source_curriculum_id');
        });
    }
};
