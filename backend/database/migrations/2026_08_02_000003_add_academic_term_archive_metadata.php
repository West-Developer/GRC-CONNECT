<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_terms', function (Blueprint $table): void {
            $table->dateTime('closed_at')->nullable()->after('status');
            $table->dateTime('archived_at')->nullable()->after('closed_at');
        });

        Schema::create('academic_term_current_slots', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->foreignId('academic_term_id')->nullable()
                ->constrained('academic_terms')
                ->nullOnDelete();
            $table->timestamps();
        });

        DB::table('academic_term_current_slots')->insert([
            'id' => 1,
            'academic_term_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_term_current_slots');

        Schema::table('academic_terms', function (Blueprint $table): void {
            $table->dropColumn(['closed_at', 'archived_at']);
        });
    }
};
