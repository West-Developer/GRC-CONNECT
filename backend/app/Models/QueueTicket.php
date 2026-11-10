<?php

namespace App\Models;

use App\Domain\Enrollment\QueueTicketPriority;
use App\Domain\Enrollment\QueueTicketStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ticket belongs to an enrollment (a student who claimed at the kiosk) or,
 * for a freshman who has paid nothing yet and has no enrollment, to a student
 * profile — never neither (a CHECK enforces it). `public_token_hash` is the
 * SHA-256 of the token in the freshman's emailed "view my queue number" link.
 *
 * @property int $id
 * @property ?int $enrollment_id
 * @property ?int $student_profile_id
 * @property ?string $public_token_hash
 * @property ?CarbonImmutable $link_sent_at
 * @property ?CarbonImmutable $link_failed_at
 * @property int $queue_cycle_id
 * @property int $ticket_sequence
 * @property string $ticket_number
 * @property CarbonImmutable $queue_date
 * @property QueueTicketStatus $status
 * @property QueueTicketPriority $priority
 * @property ?CarbonImmutable $served_at
 * @property ?int $served_by
 * @property ?CarbonImmutable $requeued_at
 * @property int $announce_count
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read ?Enrollment $enrollment
 * @property-read ?StudentProfile $studentProfile
 * @property-read ?User $server
 */
final class QueueTicket extends Model
{
    /** Set by `preloadPositions()`; while set, `position()` answers from it without querying. */
    private ?int $preloadedPosition = null;

    private bool $hasPreloadedPosition = false;

    /** @var list<string> */
    protected $fillable = [
        'enrollment_id',
        'student_profile_id',
        'public_token_hash',
        'link_sent_at',
        'link_failed_at',
        'queue_cycle_id',
        'ticket_number',
        'ticket_sequence',
        'queue_date',
        'status',
        'priority',
        'served_at',
        'served_by',
        'requeued_at',
        'announce_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QueueTicketStatus::class,
            'priority' => QueueTicketPriority::class,
            'queue_date' => 'immutable_date',
            'served_at' => 'immutable_datetime',
            'requeued_at' => 'immutable_datetime',
            'link_sent_at' => 'immutable_datetime',
            'link_failed_at' => 'immutable_datetime',
            'announce_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * Set for a freshman's ticket (issued before any enrollment exists).
     *
     * @return BelongsTo<StudentProfile, $this>
     */
    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    /**
     * Whose ticket this is, whichever owner it hangs off. Callers that list
     * tickets should eager-load both `enrollment.student` and `studentProfile`.
     */
    public function student(): ?StudentProfile
    {
        // `??` already treats a missing enrollment as "not set", so no nullsafe call is needed.
        return $this->enrollment->student ?? $this->studentProfile;
    }

    public static function hashPublicToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The ticket a "view my queue number" link points at; `null` for an empty
     * or unknown token. Only the hash is stored, so this compares hashes.
     */
    public static function findByPublicToken(string $token): ?self
    {
        if ($token === '') {
            return null;
        }

        return self::query()->where('public_token_hash', self::hashPublicToken($token))->first();
    }

    /**
     * @return BelongsTo<QueueCycle, $this>
     */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(QueueCycle::class, 'queue_cycle_id');
    }

    /**
     * The Accounting Staff member who called this ticket. Deliberately
     * never exposed via `QueueTicketResource` — actor identity is never
     * rendered to students, the same privacy convention every audited
     * action in this codebase follows.
     *
     * @return BelongsTo<User, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(User::class, 'served_by');
    }

    /**
     * The instant this ticket sorts by within its priority tier:
     * `requeued_at` if it's ever been skipped, otherwise `created_at`.
     * `created_at` is not cast in `casts()` (only `served_at`/`requeued_at`
     * are), so it comes back as a plain `Illuminate\Support\Carbon`, not a
     * `CarbonImmutable` — hence the `CarbonInterface` return type, which
     * both implement and which is all `format()` below needs. The final
     * `?? CarbonImmutable::now()` only matters for an unsaved, not-yet-
     * persisted model (no real `queue_tickets` row ever lacks
     * `created_at`) — treating that edge case as "sorts as if issued right
     * now" is honest and safe, not a behavior change for any real ticket.
     */
    private function effectiveOrder(): CarbonInterface
    {
        return $this->requeued_at ?? $this->created_at ?? CarbonImmutable::now();
    }

    /**
     * How many other `waiting` tickets stand ahead of this one in the
     * current open cycle — the whole queue is never exposed to a student
     * (privacy), only their own count. `null` once this ticket has left
     * `waiting`. Priority tickets always precede regular ones within the
     * same cycle; within a tier, ordered by `queue_date` (a cycle can span
     * multiple Manila service days once a cut-off carries tickets forward
     * — a carry-over always sorts ahead of a same-cycle ticket claimed on a
     * later date), then `COALESCE(requeued_at, created_at)` — arrival
     * order for a never-skipped ticket, or the moment it was last requeued
     * for one that was — with `id` as the final tiebreaker for a true
     * timestamp tie. Mirrors `App\Actions\Enrollment\ListQueueTickets`'s
     * ordering exactly; see docs/superpowers/specs/
     * 2026-08-23-queue-kiosk-claim-carryover-cutoff-design.md for why the
     * `queue_date` term is required once a cycle spans multiple dates.
     */
    public function position(): ?int
    {
        if ($this->status !== QueueTicketStatus::Waiting) {
            return null;
        }

        if ($this->hasPreloadedPosition) {
            return $this->preloadedPosition;
        }

        $waitingInCycle = self::query()
            ->where('queue_cycle_id', $this->queue_cycle_id)
            ->where('status', QueueTicketStatus::Waiting);

        $applyOrderedBefore = function ($query): void {
            $selfDate = $this->queue_date->toDateString();
            $effectiveOrder = $this->effectiveOrder()->format('Y-m-d H:i:s');
            $selfWasRequeued = (int) ($this->requeued_at !== null);

            $query->where(function ($query) use ($selfDate, $effectiveOrder, $selfWasRequeued) {
                $query->where('queue_date', '<', $selfDate)
                    ->orWhere(function ($query) use ($selfDate, $effectiveOrder, $selfWasRequeued) {
                        $query->where('queue_date', $selfDate)
                            ->where(function ($query) use ($effectiveOrder, $selfWasRequeued) {
                                $query->whereRaw('COALESCE(requeued_at, created_at) < ?', [$effectiveOrder])
                                    ->orWhere(function ($query) use ($effectiveOrder, $selfWasRequeued) {
                                        $query->whereRaw('COALESCE(requeued_at, created_at) = ?', [$effectiveOrder])
                                            ->where(function ($query) use ($selfWasRequeued) {
                                                $query->whereRaw('(requeued_at IS NOT NULL) < ?', [$selfWasRequeued])
                                                    ->orWhere(function ($query) use ($selfWasRequeued) {
                                                        $query->whereRaw('(requeued_at IS NOT NULL) = ?', [$selfWasRequeued])
                                                            ->where('id', '<', $this->id);
                                                    });
                                            });
                                    });
                            });
                    });
            });
        };

        if ($this->priority === QueueTicketPriority::Priority) {
            $priorityQuery = (clone $waitingInCycle)->where('priority', QueueTicketPriority::Priority);
            $applyOrderedBefore($priorityQuery);

            return $priorityQuery->count();
        }

        $priorityAhead = (clone $waitingInCycle)
            ->where('priority', QueueTicketPriority::Priority)
            ->count();

        $regularQuery = (clone $waitingInCycle)->where('priority', QueueTicketPriority::Regular);
        $applyOrderedBefore($regularQuery);
        $regularAhead = $regularQuery->count();

        return $priorityAhead + $regularAhead;
    }

    /**
     * Computes `position()` for a whole page of tickets from ONE query, so a
     * list endpoint no longer pays one to three COUNT queries per waiting
     * ticket on every poll (ADR 0029). Within a cycle a ticket's position is
     * simply its index in the waiting line, ordered exactly as `position()` and
     * `ListQueueTickets` order it: priority tier first, then `queue_date`, then
     * `COALESCE(requeued_at, created_at)`, then never-requeued before requeued,
     * then `id`. Tickets that are not waiting are skipped (their position is
     * `null` anyway). The answer lives only on these instances, for the request
     * at hand; `refresh()` drops it.
     *
     * @param  iterable<self>  $tickets
     */
    public static function preloadPositions(iterable $tickets): void
    {
        $waiting = [];
        foreach ($tickets as $ticket) {
            if ($ticket->status === QueueTicketStatus::Waiting) {
                $waiting[] = $ticket;
            }
        }

        if ($waiting === []) {
            return;
        }

        $cycleIds = array_values(array_unique(array_map(
            static fn (self $ticket): int => $ticket->queue_cycle_id,
            $waiting,
        )));

        $positionById = [];
        $rows = self::query()
            ->whereIn('queue_cycle_id', $cycleIds)
            ->where('status', QueueTicketStatus::Waiting)
            ->get(['id', 'queue_cycle_id', 'priority', 'queue_date', 'requeued_at', 'created_at']);

        foreach ($rows->groupBy('queue_cycle_id') as $cycleRows) {
            $sorted = $cycleRows
                ->sort(static fn (self $a, self $b): int => $a->lineOrderKey() <=> $b->lineOrderKey())
                ->values();

            foreach ($sorted as $index => $row) {
                $positionById[$row->id] = $index;
            }
        }

        foreach ($waiting as $ticket) {
            if (isset($positionById[$ticket->id])) {
                $ticket->preloadedPosition = $positionById[$ticket->id];
                $ticket->hasPreloadedPosition = true;
            }
        }
    }

    /** @return array{0: int, 1: string, 2: string, 3: int, 4: int} */
    private function lineOrderKey(): array
    {
        return [
            $this->priority === QueueTicketPriority::Priority ? 0 : 1,
            $this->queue_date->toDateString(),
            $this->effectiveOrder()->format('Y-m-d H:i:s'),
            $this->requeued_at !== null ? 1 : 0,
            $this->id,
        ];
    }

    public function refresh(): static
    {
        $this->hasPreloadedPosition = false;
        $this->preloadedPosition = null;

        return parent::refresh();
    }
}
