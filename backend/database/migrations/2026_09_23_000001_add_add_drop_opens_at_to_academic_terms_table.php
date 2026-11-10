<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_terms', function (Blueprint $table): void {
            $table->dateTime('add_drop_opens_at')->nullable()->after('enrollment_closes_at');
        });
    }

    public function down(): void
    {
        Schema::table('academic_terms', function (Blueprint $table): void {
            $table->dropColumn('add_drop_opens_at');
        });
    }
};

