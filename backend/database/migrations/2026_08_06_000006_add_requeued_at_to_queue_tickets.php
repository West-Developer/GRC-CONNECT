<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skip no longer cancels a ticket (see `TransitionQueueTicket`) -- it
 * requeues the ticket to the back of its priority tier instead. Ordering
 * is otherwise purely `id`-based (immutable, monotonic), so there is no
 * way to "move a ticket to the back" without a new sortable marker. This
 * nullable timestamp is that marker: null for a never-skipped ticket
 * (which sorts by `created_at`, unchanged), set to the skip moment for a
 * requeued one, so it naturally sorts after every ticket issued or last
 * requeued before it. See `QueueTicket::position()` and `ListQueueTickets`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->timestamp('requeued_at')->nullable()->after('served_by');
        });
    }

    public function down(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->dropColumn('requeued_at');
        });
    }
};
