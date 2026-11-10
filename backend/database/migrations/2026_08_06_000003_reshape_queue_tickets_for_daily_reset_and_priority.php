<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §17 leaves "queue-ticket reset, priority, active serving-number
 * policy" unconfirmed — this migration gives that a mechanism (a daily
 * reset and a priority flag) without asserting the policy itself is final.
 *
 * `ticket_number` was globally unique (`Q%06d` derived from the enrollment
 * id) — a number that only ever grows and never resets. The reset this
 * slice implements requires numbers to repeat across days (`Q001` every
 * morning), so the constraint moves to the composite `(queue_date,
 * ticket_number)` — still enough to make a duplicate impossible within one
 * day, which is the only scope a real front-desk queue ever needs.
 *
 * `priority` is cashier-set after issuance (a real front-desk case: "this
 * student has a documented reason to go first"), not decided at approval
 * time — so it is a plain ordering flag, not baked into the ticket number's
 * prefix. Renumbering an already-issued ticket when priority changes would
 * risk invalidating a number a student may already be holding/showing.
 *
 * `served_by` records which Accounting Staff member called a ticket — kept
 * out of `QueueTicketResource` entirely (never returned to students), the
 * same actor-privacy convention every audited action in this codebase
 * already follows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->dropUnique('queue_tickets_ticket_number_unique');
            $table->string('priority')->default('regular')->after('status');
            $table->foreignId('served_by')->nullable()->after('served_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->unique(['queue_date', 'ticket_number']);
        });
    }

    public function down(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->dropUnique(['queue_date', 'ticket_number']);
            $table->dropConstrainedForeignId('served_by');
            $table->dropColumn('priority');
        });

        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->unique('ticket_number');
        });
    }
};
