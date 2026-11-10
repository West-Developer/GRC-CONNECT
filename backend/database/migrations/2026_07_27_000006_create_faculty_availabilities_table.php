<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §10.2 faculty availability (DFD 1.2 "Gather Faculty Input").
 *
 * `day_of_week` uses ISO-8601 numbering (1 = Monday … 7 = Sunday) so it is
 * unambiguous across locales. Times are stored as wall-clock TIME values
 * because an availability window is a recurring weekly slot, not an instant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faculty_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('academic_term_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at_time');
            $table->time('ends_at_time');
            $table->timestamps();

            $table->unique(
                ['professor_id', 'academic_term_id', 'day_of_week', 'starts_at_time'],
                'faculty_availability_unique_slot',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faculty_availabilities');
    }
};
