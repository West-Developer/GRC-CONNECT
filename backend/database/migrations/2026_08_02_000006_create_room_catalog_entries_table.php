<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_catalog_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('college');
            $table->timestamps();

            $table->unique(['name', 'college'], 'room_catalog_name_college_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_catalog_entries');
    }
};
