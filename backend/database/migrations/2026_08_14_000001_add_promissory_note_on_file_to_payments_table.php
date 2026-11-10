<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A hard-copy promissory note is never stored in the system. This field is
 * only the Cashier's operational indication that it is on file for the
 * qualifying enrollment payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->boolean('promissory_note_on_file')->default(false)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('promissory_note_on_file');
        });
    }
};
