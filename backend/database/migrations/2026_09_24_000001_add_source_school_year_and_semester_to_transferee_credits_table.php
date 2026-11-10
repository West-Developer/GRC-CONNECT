<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transferee_credits', function (Blueprint $table) {
            $table->string('source_school_year', 32)->nullable()->after('credited_units');
            $table->string('source_semester', 32)->nullable()->after('source_school_year');
        });
    }

    public function down(): void
    {
        Schema::table('transferee_credits', function (Blueprint $table) {
            $table->dropColumn(['source_school_year', 'source_semester']);
        });
    }
};

