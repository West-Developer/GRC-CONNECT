<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faculty_specializations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('professor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->string('proficiency', 16)->default('secondary');
            $table->string('source', 32)->default('declared');
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['professor_id', 'subject_id'], 'faculty_specialization_unique_subject');
            $table->index(['professor_id', 'proficiency'], 'faculty_specialization_prof_prof_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faculty_specializations');
    }
};
