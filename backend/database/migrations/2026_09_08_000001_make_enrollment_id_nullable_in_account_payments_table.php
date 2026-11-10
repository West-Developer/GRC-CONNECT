<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_payments', function (Blueprint $table) {
            $table->foreignId('enrollment_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('account_payments', function (Blueprint $table) {
            $table->foreignId('enrollment_id')->nullable(false)->change();
        });
    }
};

