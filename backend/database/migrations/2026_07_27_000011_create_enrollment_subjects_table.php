<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §10.3 enrollment/section mapping.
 *
 * The unique constraint is what makes PRD §5.3's "repeated requests must not
 * duplicate seats" enforceable at the storage layer rather than trusting the
 * application to check first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->string('status');
            $table->timestamps();

            $table->unique(['enrollment_id', 'section_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_subjects');
    }
};
