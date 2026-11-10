<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_prerequisites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prerequisite_subject_id')->constrained('subjects')->restrictOnDelete();
            $table->string('minimum_grade');
            $table->timestamps();

            $table->unique(['curriculum_subject_id', 'prerequisite_subject_id'], 'subject_prereq_unique_mapping');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_prerequisites');
    }
};
