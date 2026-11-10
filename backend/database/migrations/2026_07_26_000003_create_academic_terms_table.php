<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_terms', function (Blueprint $table) {
            $table->id();
            $table->string('school_year');
            $table->string('semester');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->dateTime('enrollment_opens_at')->nullable();
            $table->dateTime('enrollment_closes_at')->nullable();
            $table->string('status');
            $table->timestamps();

            $table->unique(['school_year', 'semester']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_terms');
    }
};
