<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_schedules', function (Blueprint $table): void {
            $table->id();
            $table->string('category'); // 'tuition', 'miscellaneous'
            $table->string('label');
            $table->decimal('amount', 10, 2)->default(0.00);
            $table->json('program_codes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['category', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_schedules');
    }
};
