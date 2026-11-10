<?php

namespace App\Actions\Enrollment;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\QueueServiceDate;
use App\Domain\Enrollment\QueueTicketStatus;
use App\Domain\Notifications\NotificationType;
use App\Models\QueueCycle;
use App\Models\QueueTicket;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Notifications\NotificationRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cut-off/resume for the single open queue cycle (PRD §5.3 FR-FIN-006).
 * Cutting off does NOT close the cycle — it only records that Accounting
 * Staff stopped serving for today, so the student-facing queue view can
 * show a notice and `TransitionQueueTicket::execute()`'s `serve` guard can
 * block further calls. The very next successful claim on a later Manila
 * service date resumes automatically (see `ClaimQueueTicket`), so this
 * `resume` exists only for "we changed our mind, reopening today."
 *
 * A ticket left `serving` across a cut-off is returned to `waiting`
 * WITHOUT stamping `requeued_at`: it was never actually served, so it
 * keeps its place rather than losing it to the back of the line. See
 * docs/superpowers/specs/2026-08-23-queue-kiosk-claim-carryover-cutoff-design.md.
 */
final readonly class TransitionQueueCycle
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private NotificationRecorder $notificationRecorder,
    ) {}

    public function cutOff(User $actor, AuditRequestContext $context): QueueCycle
    {
        return DB::transaction(function () use ($actor, $context): QueueCycle {
            $cycle = QueueCycle::query()->whereNull('closed_at')->lockForUpdate()->first();

            if ($cycle === null) {
                throw ValidationException::withMessages(['cycle' => 'No queue is currently open.']);
            }

            $today = QueueServiceDate::today();

            if ($cycle->cut_off_service_date !== null && $cycle->cut_off_service_date->toDateString() === $today) {
                throw ValidationException::withMessages(['cycle' => 'The queue is already cut off for today.']);
            }

            $beforeValues = self::snapshot($cycle);

            QueueTicket::query()
                ->where('queue_cycle_id', $cycle->id)
                ->where('status', QueueTicketStatus::Serving)
                ->update(['status' => QueueTicketStatus::Waiting]);

            $cycle->update([
                'cut_off_at' => now(),
                'cut_off_service_date' => $today,
                'cut_off_by' => $actor->id,
            ]);
            $cycle->refresh();

            $this->auditRecorder->record(
                $actor,
                AuditAction::QUEUE_CYCLE_CUT_OFF,
                AuditableType::QUEUE_CYCLE,
                $cycle->id,
                $beforeValues,
                self::snapshot($cycle),
                null,
                $context,
            );

            // A waiting ticket may belong to a freshman who has no enrollment yet.
            $waitingStudentUserIds = array_values(QueueTicket::query()
                ->where('queue_cycle_id', $cycle->id)
                ->where('status', QueueTicketStatus::Waiting)
                ->with(['enrollment.student', 'studentProfile'])
                ->get()
                ->map(fn (QueueTicket $ticket): ?int => $ticket->student()?->user_id)
                ->filter()
                ->all());

            $this->notificationRecorder->recordMany(
                $waitingStudentUserIds,
                NotificationType::QueueCycleCutOff,
                'The Cashier has closed the queue for today. Your place in line is saved -- you do not need to claim a new ticket.',
            );

            return $cycle;
        });
    }

    public function resume(User $actor, AuditRequestContext $context): QueueCycle
    {
        return DB::transaction(function () use ($actor, $context): QueueCycle {
            $cycle = QueueCycle::query()->whereNull('closed_at')->lockForUpdate()->first();

            if ($cycle === null || $cycle->cut_off_service_date === null) {
                throw ValidationException::withMessages(['cycle' => 'The queue is not currently cut off.']);
            }

            $beforeValues = self::snapshot($cycle);

            $cycle->update(['cut_off_at' => null, 'cut_off_service_date' => null, 'cut_off_by' => null]);
            $cycle->refresh();

            $this->auditRecorder->record(
                $actor,
                AuditAction::QUEUE_CYCLE_RESUMED,
                AuditableType::QUEUE_CYCLE,
                $cycle->id,
                $beforeValues,
                self::snapshot($cycle),
                null,
                $context,
            );

            return $cycle;
        });
    }

    /**
     * @return array{cut_off_at: ?string, cut_off_service_date: ?string}
     */
    private static function snapshot(QueueCycle $cycle): array
    {
        return [
            'cut_off_at' => $cycle->cut_off_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'cut_off_service_date' => $cycle->cut_off_service_date?->toDateString(),
        ];
    }
}
