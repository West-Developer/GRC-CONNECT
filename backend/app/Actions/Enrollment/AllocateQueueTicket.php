<?php

namespace App\Actions\Enrollment;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\QueueServiceDate;
use App\Domain\Enrollment\QueueTicketPriority;
use App\Domain\Enrollment\QueueTicketStatus;
use App\Models\QueueCycle;
use App\Models\QueueTicket;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The one place a queue number is handed out. Shared by `ClaimQueueTicket`
 * (a student with a `pending_payment` enrollment) and
 * `IssueFreshmanQueueTicket` (a freshman who has no enrollment yet), so both
 * draw from the same open `queue_cycles` row and the same `Q%03d` sequence —
 * a freshman waits in the same line as everyone else.
 *
 * Numbering is scoped to the single open cycle, not the calendar day — a cycle
 * can span several service days once a cut-off carries tickets forward (see
 * `QueueCycle`). Allocation locks that one row (`lockForUpdate`), never
 * `queue_tickets`, so concurrent claims serialize on a single integer bump.
 * See docs/superpowers/specs/2026-08-23-queue-kiosk-claim-carryover-cutoff-design.md.
 *
 * Idempotent by construction: `$findExisting` is checked before the
 * transaction and again inside it, and the unique keys on `queue_tickets`
 * (`enrollment_id`, `student_profile_id`, `(queue_cycle_id, ticket_sequence)`)
 * are the database backstop. A 23000 collision is a concurrent allocation and
 * is retried.
 */
final readonly class AllocateQueueTicket
{
    private const MAX_ALLOCATION_ATTEMPTS = 3;

    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * @param  array<string, mixed>  $owner  the ticket's owner columns (`enrollment_id` or `student_profile_id`, and any extras such as `public_token_hash`)
     * @param  Closure(): ?QueueTicket  $findExisting  the ticket this owner already holds, if any
     * @param  ?Closure(): void  $guard  runs inside the transaction, after the existing-ticket check, before anything is written; throw to refuse
     * @param  ?Closure(QueueTicket): void  $afterCreate  runs inside the transaction once the ticket exists
     * @param  array<string, mixed>  $auditExtras  merged into the audit row's after-values
     */
    public function execute(
        array $owner,
        Closure $findExisting,
        User $actor,
        AuditRequestContext $context,
        ?Closure $guard = null,
        ?Closure $afterCreate = null,
        array $auditExtras = [],
    ): QueueTicket {
        $existing = $findExisting();

        if ($existing !== null) {
            return $existing;
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($owner, $findExisting, $actor, $context, $guard, $afterCreate, $auditExtras): QueueTicket {
                    // A concurrent claim for the same owner may have committed
                    // between the pre-check above and this attempt starting.
                    $existing = $findExisting();

                    if ($existing !== null) {
                        return $existing;
                    }

                    if ($guard !== null) {
                        $guard();
                    }

                    $ticket = $this->create($owner, $actor, $context, $auditExtras);

                    if ($afterCreate !== null) {
                        $afterCreate($ticket);
                    }

                    return $ticket;
                });
            } catch (QueryException $exception) {
                // The only unique constraints reachable inside create() are
                // queue_cycles_single_open_cycle_unique and
                // (queue_cycle_id, ticket_sequence); the owner's own unique key
                // is covered by the pre-checks above, so any 23000 here is an
                // allocation collision from a concurrent claim, safe to retry.
                if ($attempt >= self::MAX_ALLOCATION_ATTEMPTS || $exception->getCode() !== '23000') {
                    throw $exception;
                }

                usleep(random_int(2_000, 20_000));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $owner
     * @param  array<string, mixed>  $auditExtras
     */
    private function create(array $owner, User $actor, AuditRequestContext $context, array $auditExtras): QueueTicket
    {
        $today = QueueServiceDate::today();
        $cycle = QueueCycle::query()->whereNull('closed_at')->lockForUpdate()->first();

        if ($cycle !== null
            && $cycle->last_claimed_on !== null
            && $cycle->last_claimed_on->toDateString() < $today
            && $cycle->isDrained()) {
            $cycle->update(['closed_at' => now()]);
            $this->auditRecorder->record(
                $actor,
                AuditAction::QUEUE_CYCLE_CLOSED,
                AuditableType::QUEUE_CYCLE,
                $cycle->id,
                ['closed_at' => null],
                ['closed_at' => now()->utc()->format('Y-m-d\TH:i:s\Z')],
                null,
                $context,
            );
            $cycle = null;
        }

        if ($cycle === null) {
            // RACE: two claims can both see no open cycle and both INSERT.
            // There is no row to lock, so lockForUpdate() cannot serialize
            // this specific case — the single-open-cycle generated-column
            // unique index is what makes the loser's insert throw 23000;
            // execute()'s retry then finds and locks the winner's row.
            $cycle = QueueCycle::create(['opened_on' => $today, 'last_ticket_sequence' => 0]);
            $cycle = QueueCycle::query()->whereKey($cycle->id)->lockForUpdate()->firstOrFail();
        }

        $sequence = $cycle->last_ticket_sequence + 1;

        $ticket = QueueTicket::create([
            ...$owner,
            'queue_cycle_id' => $cycle->id,
            'ticket_sequence' => $sequence,
            'ticket_number' => sprintf('Q%03d', $sequence),
            'queue_date' => $today,
            'status' => QueueTicketStatus::Waiting,
            'priority' => QueueTicketPriority::Regular,
        ]);

        $cycle->update(['last_ticket_sequence' => $sequence, 'last_claimed_on' => $today]);

        $this->auditRecorder->record(
            $actor,
            AuditAction::QUEUE_TICKET_CLAIMED,
            AuditableType::QUEUE_TICKET,
            $ticket->id,
            null,
            ['ticket_number' => $ticket->ticket_number, 'queue_cycle_id' => $cycle->id, ...$auditExtras],
            null,
            $context,
        );

        return $ticket;
    }
}
