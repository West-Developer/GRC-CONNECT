<?php

namespace App\Actions\Academic;

use App\Domain\Academic\TransfereeCreditStatus;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\QueueLinkOutbox;
use App\Domain\Identity\UserRole;
use App\Domain\Notifications\NotificationType;
use App\Models\StudentProfile;
use App\Models\TransfereeCredit;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Notifications\NotificationRecorder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Program Chair sends a whole student to the Registrar Head in one step
 * (ADR 0026, amended): every `pending` credit of that student that has a mapped
 * subject becomes `endorsed`, and the Registrar Head gets one notice with the
 * count.
 *
 * All or nothing: if any pending credit still has no mapped subject, nothing is
 * endorsed and the answer names the credits that are missing one (a credit the
 * Chair has no equivalent for is declined first, which makes it `rejected`, not
 * `pending`). A fresh endorsement also clears any earlier return. Idempotent: the
 * student's pending credits are locked first, so a repeat finds none, changes
 * nothing, notifies nobody and returns an empty collection. Who may call this is
 * `TransfereeCreditPolicy::manageStudent`.
 *
 * Owner, 2026-10-15: from here the student may enrol. The endorsed credits count for them
 * provisionally (their Regular/Irregular category is re-derived) and, with nothing left
 * `pending`, they get their queue number; the Registrar Head's approval comes after.
 */
final readonly class SubmitCreditsToRegistrarHead
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private NotificationRecorder $notificationRecorder,
        private IssueTransfereeQueueTicket $issueQueueTicket,
        private ReclassifyAfterCreditApproval $reclassifyAfterApproval,
    ) {}

    /**
     * `$outbox` collects the queue-number link this step issued, for the caller to email after
     * commit (`SendQueueLinks`); a caller that does not send emails may leave it out.
     *
     * @return Collection<int, TransfereeCredit> the credits this call endorsed (empty on a repeat)
     */
    public function execute(StudentProfile $student, User $chair, AuditRequestContext $context, ?QueueLinkOutbox $outbox = null): Collection
    {
        return DB::transaction(function () use ($student, $chair, $context, $outbox): Collection {
            $pending = TransfereeCredit::query()
                ->where('student_id', $student->id)
                ->where('status', TransfereeCreditStatus::Pending->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($pending->isEmpty()) {
                return $pending;
            }

            $unmapped = $pending->filter(static fn (TransfereeCredit $credit): bool => $credit->subject_id === null);

            if ($unmapped->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'credits' => [sprintf(
                        'Map or decline every request before sending it to the Registrar Head. Still unmapped: %s.',
                        $unmapped->pluck('source_subject_title')->implode(', '),
                    )],
                ]);
            }

            $endorsedAt = now();

            foreach ($pending as $credit) {
                $credit->forceFill([
                    'status' => TransfereeCreditStatus::Endorsed,
                    'endorsed_by' => $chair->id,
                    'endorsed_at' => $endorsedAt,
                    'returned_by' => null,
                    'returned_at' => null,
                    'return_reason' => null,
                ])->save();

                $this->auditRecorder->record(
                    $chair,
                    AuditAction::TRANSFEREE_CREDIT_ENDORSED,
                    AuditableType::TRANSFEREE_CREDIT,
                    $credit->id,
                    ['status' => TransfereeCreditStatus::Pending->value],
                    ['status' => TransfereeCreditStatus::Endorsed->value, 'subject_id' => $credit->subject_id],
                    null,
                    $context,
                );
            }

            $count = $pending->count();

            $this->notificationRecorder->recordManyForRole(
                UserRole::RegistrarHead,
                NotificationType::TransfereeCreditEndorsed,
                sprintf(
                    '%d %s for student %s %s endorsed by the Program Head and await your approval.',
                    $count,
                    $count === 1 ? 'credit' : 'credits',
                    $student->student_number,
                    $count === 1 ? 'was' : 'were',
                ),
            );

            // The endorsed credits count for the student now, so their category may change, and with
            // nothing left pending they get their queue number.
            $this->reclassifyAfterApproval->execute($student, $chair, $context);
            $this->issueQueueTicket->whenMappingCloses($student, $chair, $context, $outbox);

            return $pending->load(['student.user', 'subject']);
        });
    }
}
