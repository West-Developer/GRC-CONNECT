<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_schedule_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained('student_profiles')->cascadeOnDelete();
            $table->json('preferred_days')->nullable();          // ISO-8601 1..6
            $table->string('preferred_time_block', 16)->default('any');
            $table->string('preferred_modality', 16)->nullable();
            $table->unsignedTinyInteger('max_days_on_campus')->nullable();
            $table->boolean('avoid_early_first_class')->default(false);
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_schedule_preferences');
    }
};
