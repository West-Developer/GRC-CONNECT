<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Announce ticket" used to be a purely local sound on the Cashier's own
 * device, so a Student only ever heard the call if their own device happened
 * to poll at the right moment. The student's queue view is already polled
 * every three seconds (ADR 0023: no push, no websockets), so the Cashier's
 * announce is made visible to it as a counter: each press adds one, and the
 * student's device rings again whenever it sees the number go up while its
 * own ticket is `serving`. See ADR 0027 and `TransitionQueueTicket`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->unsignedSmallInteger('announce_count')->default(0)->after('requeued_at');
        });
    }

    public function down(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->dropColumn('announce_count');
        });
    }
};
