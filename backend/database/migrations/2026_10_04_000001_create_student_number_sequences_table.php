<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The running counter behind sequential student numbers (`YYYY-MM-NNNNN`,
 * ADR 0042): one row per calendar year holding the last suffix handed out. The
 * row for a year is created on first use from the highest number already in
 * `student_profiles`, so it never needs seeding and a restored older dump
 * cannot make it hand out a number that is already taken.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_number_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_value')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_number_sequences');
    }
};
