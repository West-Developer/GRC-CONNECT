<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The student details the CHED forms ask for and the system never stored (ADR 0049): gender,
 * birth date, citizenship, a contact number and the street / town / province split of the
 * address. All nullable, so no existing row changes. `demographics_demo_fields` lists the fields
 * `students:populate-demographics` filled with generated values, so they stay recognisable and
 * `--wipe` can take exactly those back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->string('gender', 1)->nullable();
            // `date`, not `timestamp`: a birth date has no time and must not shift with the session time zone.
            $table->date('birth_date')->nullable();
            $table->string('citizenship', 100)->nullable();
            $table->string('contact_number', 30)->nullable();
            $table->string('street_barangay', 255)->nullable();
            $table->string('town_city', 255)->nullable();
            $table->string('province', 255)->nullable();
            $table->json('demographics_demo_fields')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'gender',
                'birth_date',
                'citizenship',
                'contact_number',
                'street_barangay',
                'town_city',
                'province',
                'demographics_demo_fields',
            ]);
        });
    }
};
