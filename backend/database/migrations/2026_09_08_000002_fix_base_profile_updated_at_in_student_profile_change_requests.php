<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profile_change_requests', function (Blueprint $table): void {
            $table->timestamp('base_profile_updated_at')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('student_profile_change_requests', function (Blueprint $table): void {
            $table->timestamp('base_profile_updated_at')->nullable(false)->change();
        });
    }
};

