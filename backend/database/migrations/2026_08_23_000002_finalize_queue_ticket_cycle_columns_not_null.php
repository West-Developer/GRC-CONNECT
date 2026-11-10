<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every ticket-issuing code path is now cycle-aware -- ClaimQueueTicket is
 * the only one left, since this plan's prior task removed
 * TransitionEnrollment's old registrar-approval-time auto-issue.
 * `queue_cycle_id`/`ticket_sequence` (added nullable in this plan's first
 * migration and backfilled) can now be required, and get their own
 * uniqueness guarantee.
 *
 * Uses a raw ALTER TABLE for the NOT NULL tightening rather than
 * `Blueprint::change()`, which this project would otherwise need
 * `doctrine/dbal` for -- a dependency nothing else in the project
 * requires.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE queue_tickets MODIFY queue_cycle_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE queue_tickets MODIFY ticket_sequence INT UNSIGNED NOT NULL');

        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->unique(['queue_cycle_id', 'ticket_sequence']);
        });
    }

    public function down(): void
    {
        // The foreign key on queue_cycle_id is served by the composite unique index. MariaDB refuses
        // to drop an index a foreign key still needs (error 1553), so give the key its own plain
        // index first; it disappears with the column when the earlier migration is rolled back.
        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->index('queue_cycle_id', 'queue_tickets_queue_cycle_id_index');
        });

        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->dropUnique(['queue_cycle_id', 'ticket_sequence']);
        });

        DB::statement('ALTER TABLE queue_tickets MODIFY queue_cycle_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE queue_tickets MODIFY ticket_sequence INT UNSIGNED NULL');
    }
};
