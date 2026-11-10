<?php

namespace App\Actions\Enrollment;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\QueueCycleStatus;
use App\Domain\Enrollment\QueueTicketPriority;
use App\Domain\Enrollment\QueueTicketStatus;
use App\Models\QueueCycle;
use App\Models\QueueTicket;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * PRD §5.3 FR-FIN-006. Follows ADR 0011's shape: a `TARGET_STATUS` /
 * `REQUIRED_CURRENT_STATUS` / `AUDIT_ACTION` constant trio, a row lock, and
 * a `ValidationException` when the current status doesn't match.
 *
 * §17 leaves reset cadence and priority eligibility unconfirmed — this
 * Action enforces only the three-step order (`waiting` → `serving` →
 * `served`, with `skip` as a `waiting` re-entry — stamping `requeued_at`
 * to push the ticket to the back of its own priority tier, see
 * `QueueTicket::position()` — from either `waiting` or `serving`) and a
 * single-active-serving rule, never any numbering or eligibility policy.
 * The single-active-serving rule (and `serve`'s cut-off guard below) is
 * scoped to the ticket's `queue_cycle_id`, not `queue_date` — a cycle can
 * span multiple Manila service days once a cut-off carries tickets
 * forward, and a stale `queue_date` scope would leave a carry-over ticket
 * `serving` forever once a later-dated ticket in the same cycle is served.
 * No skip-count limit is enforced: PRD §17 leaves this whole area
 * provisional, and a cap would be inventing policy, not implementing an
 * approved one. No notification is sent: calling, completing, or skipping
 * a ticket is Accounting's own operational action, with no live-queue
 * display this slice implements to make a push notification meaningful yet.
 *
 * `mark_priority` and `announce` are separate, non-status-changing writes —
 * see their own methods below.
 */
final readonly class TransitionQueueTicket
{
    /**
     * @var array<string, QueueTicketStatus>
     */
    private const TARGET_STATUS = [
        'serve' => QueueTicketStatus::Serving,
        'complete' => QueueTicketStatus::Served,
        'skip' => QueueTicketStatus::Waiting,
    ];

    /**
     * @var array<string, list<QueueTicketStatus>>
     */
    private const REQUIRED_CURRENT_STATUS = [
        'serve' => [QueueTicketStatus::Waiting],
        'complete' => [QueueTicketStatus::Serving],
        'skip' => [QueueTicketStatus::Waiting, QueueTicketStatus::Serving],
    ];

    /**
     * @var array<string, string>
     */
    private const AUDIT_ACTION = [
        'serve' => AuditAction::QUEUE_TICKET_SERVING_STARTED,
        'complete' => AuditAction::QUEUE_TICKET_SERVED,
        'skip' => AuditAction::QUEUE_TICKET_SKIPPED,
    ];

    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(QueueTicket $ticket, string $action, User $actor, AuditRequestContext $context): QueueTicket
    {
        if ($action === 'mark_priority') {
            return $this->markPriority($ticket, $actor, $context);
        }

        if ($action === 'announce') {
            return $this->announce($ticket);
        }

        if (! isset(self::TARGET_STATUS[$action])) {
            throw new InvalidArgumentException('Unknown queue ticket transition.');
        }

        return DB::transaction(function () use ($ticket, $action, $actor, $context): QueueTicket {
            $lockedTicket = QueueTicket::query()
                ->whereKey($ticket->id)
                ->lockForUpdate()
                ->firstOrFail();
            $requiredStatuses = self::REQUIRED_CURRENT_STATUS[$action];

            if (! in_array($lockedTicket->status, $requiredStatuses, true)) {
                $expected = implode("' or '", array_map(
                    fn (QueueTicketStatus $status): string => $status->value,
                    $requiredStatuses,
                ));
                throw ValidationException::withMessages([
                    'action' => "This action requires the ticket to currently be '{$expected}'; ".
                        "it is currently '{$lockedTicket->status->value}'.",
                ]);
            }

            if ($action === 'serve') {
                $cycle = QueueCycle::query()->whereKey($lockedTicket->queue_cycle_id)->first();

                if ($cycle !== null && $cycle->status() === QueueCycleStatus::CutOff) {
                    throw ValidationException::withMessages([
                        'action' => 'The queue is cut off for today. Resume it before serving another ticket.',
                    ]);
                }
            }

            $beforeValues = self::snapshot($lockedTicket);
            $targetStatus = self::TARGET_STATUS[$action];

            if ($action === 'serve') {
                // Single-active-serving: calling a new number implicitly
                // completes whatever was already being served in this
                // cycle, rather than requiring the cashier to complete it
                // first. Scoped to queue_cycle_id, not queue_date -- see
                // this class's docblock. Not separately audited -- the
                // same bulk-update-without-per-row-audit precedent
                // ConfirmPayment already uses for EnrollmentSubject
                // transitions.
                QueueTicket::query()
                    ->where('queue_cycle_id', $lockedTicket->queue_cycle_id)
                    ->where('status', QueueTicketStatus::Serving)
                    ->whereKeyNot($lockedTicket->id)
                    ->update(['status' => QueueTicketStatus::Served, 'served_at' => now()]);
            }

            $lockedTicket->update([
                'status' => $targetStatus,
                'served_at' => $targetStatus === QueueTicketStatus::Served ? now() : $lockedTicket->served_at,
                'served_by' => $action === 'serve' ? $actor->id : $lockedTicket->served_by,
                'requeued_at' => $action === 'skip' ? now() : $lockedTicket->requeued_at,
            ]);
            $lockedTicket->refresh();

            $this->auditRecorder->record(
                $actor,
                self::AUDIT_ACTION[$action],
                AuditableType::QUEUE_TICKET,
                $lockedTicket->id,
                $beforeValues,
                self::snapshot($lockedTicket),
                null,
                $context,
            );

            return $lockedTicket->refresh()->load(['enrollment.student', 'studentProfile']);
        });
    }

    /**
     * Marks a ticket `Priority` — a plain ordering flag, not a status
     * transition (see `App\Domain\Enrollment\QueueTicketPriority`'s own
     * docblock for why this never renumbers the ticket). Only from
     * `waiting`: a ticket that's already being served or is done has
     * nothing left to reorder ahead of.
     */
    private function markPriority(QueueTicket $ticket, User $actor, AuditRequestContext $context): QueueTicket
    {
        return DB::transaction(function () use ($ticket, $actor, $context): QueueTicket {
            $lockedTicket = QueueTicket::query()
                ->whereKey($ticket->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedTicket->status !== QueueTicketStatus::Waiting) {
                throw ValidationException::withMessages([
                    'action' => "Priority can only be marked while the ticket is 'waiting'; ".
                        "it is currently '{$lockedTicket->status->value}'.",
                ]);
            }

            $beforeValues = self::snapshot($lockedTicket);

            $lockedTicket->update(['priority' => QueueTicketPriority::Priority]);
            $lockedTicket->refresh();

            $this->auditRecorder->record(
                $actor,
                AuditAction::QUEUE_TICKET_MARKED_PRIORITY,
                AuditableType::QUEUE_TICKET,
                $lockedTicket->id,
                $beforeValues,
                self::snapshot($lockedTicket),
                null,
                $context,
            );

            return $lockedTicket->refresh()->load(['enrollment.student', 'studentProfile']);
        });
    }

    /**
     * The Cashier calling a `serving` ticket out (again). Not a state change:
     * it only adds one to `announce_count`, which the Student's polled queue
     * view exposes so their own device rings when the number goes up (ADR
     * 0027). Deliberately repeatable — every press is meant to ring again, so
     * there is nothing to make idempotent — and deliberately not audited: a
     * ping is not a change to the ticket, and an audit row per press would
     * only bury the real transitions.
     */
    private function announce(QueueTicket $ticket): QueueTicket
    {
        return DB::transaction(function () use ($ticket): QueueTicket {
            $lockedTicket = QueueTicket::query()
                ->whereKey($ticket->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedTicket->status !== QueueTicketStatus::Serving) {
                throw ValidationException::withMessages([
                    'action' => "A ticket can only be announced while it is 'serving'; ".
                        "it is currently '{$lockedTicket->status->value}'.",
                ]);
            }

            $lockedTicket->increment('announce_count');

            return $lockedTicket->refresh()->load(['enrollment.student', 'studentProfile']);
        });
    }

    /**
     * @return array{status: string, priority: string, served_at: ?string, requeued_at: ?string}
     */
    private static function snapshot(QueueTicket $ticket): array
    {
        return [
            'status' => $ticket->status->value,
            'priority' => $ticket->priority->value,
            'served_at' => self::formatTimestamp($ticket->served_at),
            'requeued_at' => self::formatTimestamp($ticket->requeued_at),
        ];
    }

    private static function formatTimestamp(?CarbonImmutable $timestamp): ?string
    {
        return $timestamp?->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
