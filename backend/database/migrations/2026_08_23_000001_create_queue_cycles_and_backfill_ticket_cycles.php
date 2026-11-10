<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Introduces the queue "cycle" — one continuous line, spanning one or more
 * Manila service days once a cut-off carries unserved tickets forward. A
 * cycle opens on first claim and closes only once fully drained and a
 * Manila day has passed since its last claim (see App\Actions\Enrollment\
 * ClaimQueueTicket). `queue_cycle_id` replaces `queue_date` as the scope
 * key everywhere a query means "the current line" — `queue_date` becomes
 * an ordering key instead. See docs/superpowers/specs/
 * 2026-08-23-queue-kiosk-claim-carryover-cutoff-design.md.
 *
 * `open_marker` mirrors `enrollments.active_academic_term_id`
 * (2026_07_27_000010): a STORED generated column that is NULL unless the
 * row is live, backing a UNIQUE index — SQL unique indexes ignore NULLs,
 * so this enforces "at most one open cycle" in the database itself.
 *
 * `queue_tickets.queue_cycle_id`/`ticket_sequence` are added nullable here
 * and backfilled for existing rows; a later migration (once every ticket-
 * issuing code path is cycle-aware) tightens them to NOT NULL and adds
 * `unique(queue_cycle_id, ticket_sequence)` — see Task 7 of this plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_cycles', function (Blueprint $table) {
            $table->id();
            $table->date('opened_on');
            $table->date('last_claimed_on')->nullable();
            $table->unsignedInteger('last_ticket_sequence')->default(0);
            $table->timestamp('cut_off_at')->nullable();
            $table->date('cut_off_service_date')->nullable();
            $table->foreignId('cut_off_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unsignedTinyInteger('open_marker')
                ->nullable()
                ->storedAs('case when `closed_at` is null then 1 else null end');

            $table->unique('open_marker', 'queue_cycles_single_open_cycle_unique');
        });

        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->foreignId('queue_cycle_id')->nullable()->after('enrollment_id')
                ->constrained('queue_cycles')->restrictOnDelete();
            $table->unsignedInteger('ticket_sequence')->nullable()->after('ticket_number');
        });

        $this->backfillCycles();
    }

    public function down(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('queue_cycle_id');
            $table->dropColumn('ticket_sequence');
        });

        Schema::dropIfExists('queue_cycles');
    }

    /**
     * Every fully-completed `queue_date` (no `waiting`/`serving` ticket)
     * becomes its own closed cycle, in arrival order. Every date that DOES
     * have an outstanding ticket is merged into a single open cycle instead
     * of one cycle per date — there can only ever be one open cycle
     * system-wide (`queue_cycles_single_open_cycle_unique`), and an
     * outstanding ticket from ANY such date is still "in the line", so none
     * of them may end up in a closed cycle (closing one would strand a live
     * ticket across the deploy). If no date has outstanding tickets, every
     * backfilled cycle closes and the next claim opens a fresh one at Q001.
     * `ticket_number` is left exactly as-is on every historical row; only
     * the new internal-only `ticket_sequence` is assigned, by row order
     * within each cycle — it need not equal the digits inside the old
     * `ticket_number` string (fixture data uses both `Q001` and `Q000001`
     * forms), only be unique per cycle.
     *
     * `public` (not `private`): lets
     * tests\Feature\Database\QueueCycleMigrationTest exercise this method's
     * real logic directly (requiring this file returns a fresh instance —
     * the same anonymous-class pattern Laravel's own Migrator uses to
     * resolve a migration) rather than relying on a fragile full
     * migration-replay. Nothing outside a test ever calls this once `up()`
     * has run.
     */
    public function backfillCycles(): void
    {
        $dates = DB::table('queue_tickets')->select('queue_date')->distinct()->orderBy('queue_date')->pluck('queue_date');

        if ($dates->isEmpty()) {
            return;
        }

        $outstandingDates = DB::table('queue_tickets')
            ->whereIn('status', ['waiting', 'serving'])
            ->select('queue_date')
            ->distinct()
            ->orderBy('queue_date')
            ->pluck('queue_date');

        $historicalDates = $dates->diff($outstandingDates)->values();

        // Every fully-completed date becomes its own closed cycle -- none of
        // these hold a waiting/serving ticket, so closing them is safe.
        foreach ($historicalDates as $date) {
            $this->backfillOneCycle([$date], closed: true);
        }

        // Every date that has an outstanding ticket merges into ONE open
        // cycle -- there can only ever be one open cycle system-wide (the
        // queue_cycles_single_open_cycle_unique index enforces it), and an
        // outstanding ticket from ANY of these dates is still "in the line",
        // so none of them may end up in a closed cycle.
        if ($outstandingDates->isNotEmpty()) {
            /** @var list<string> $outstandingDateList */
            $outstandingDateList = $outstandingDates->all();
            $this->backfillOneCycle($outstandingDateList, closed: false);
        }
    }

    /**
     * @param  list<string>  $dates
     */
    public function backfillOneCycle(array $dates, bool $closed): void
    {
        if ($dates === []) {
            // Unreachable given both call sites above always pass at least
            // one date -- guards min()/max() below, which require a
            // non-empty array.
            return;
        }

        $ticketsForDates = DB::table('queue_tickets')
            ->whereIn('queue_date', $dates)
            ->orderBy('queue_date')
            ->orderByRaw('COALESCE(requeued_at, created_at)')
            ->orderByRaw('requeued_at IS NOT NULL')
            ->orderBy('id')
            ->get(['id']);

        $cycleId = DB::table('queue_cycles')->insertGetId([
            'opened_on' => min($dates),
            'last_claimed_on' => max($dates),
            'last_ticket_sequence' => $ticketsForDates->count(),
            'closed_at' => $closed ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = 0;
        foreach ($ticketsForDates as $ticket) {
            $sequence++;
            DB::table('queue_tickets')->where('id', $ticket->id)->update([
                'queue_cycle_id' => $cycleId,
                'ticket_sequence' => $sequence,
            ]);
        }
    }
};
