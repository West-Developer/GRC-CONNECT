<?php

namespace App\Actions\Academic;

use App\Domain\Academic\TransfereeCreditStatus;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\QueueLinkOutbox;
use App\Domain\Notifications\NotificationType;
use App\Models\Notification;
use App\Models\StudentProfile;
use App\Models\TransfereeCredit;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Registrar Head approves all of one student's endorsed credits in a single
 * step (ADR 0026, amended). Only `endorsed` credits are touched (a pending
 * credit has not been sent to the Head, a draft is private, a decided one is
 * decided), each becomes `approved` with `processed_by`/`processed_at`, and the
 * student gets one notice with the count. An approval records that the subject is
 * credited (see `ResolveCreditedSubjectIds`) and writes nothing to any other
 * table.
 *
 * Idempotent: the student's endorsed credits are locked first, so a repeat
 * finds none, changes nothing, notifies nobody and returns an empty collection.
 * Who may call this is `TransfereeCreditPolicy::decide` (the Registrar Head).
 */
final readonly class ApproveStudentCredits
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private IssueTransfereeQueueTicket $issueQueueTicket,
        private ReclassifyAfterCreditApproval $reclassifyAfterApproval,
    ) {}

    /**
     * `$outbox` collects the queue-number link issued when this approval closes the student's last
     * open row, for the caller to email after commit (`SendQueueLinks`).
     *
     * @return Collection<int, TransfereeCredit> the credits this call approved (empty on a repeat)
     */
    public function execute(StudentProfile $student, User $head, AuditRequestContext $context, ?QueueLinkOutbox $outbox = null): Collection
    {
        return DB::transaction(function () use ($student, $head, $context, $outbox): Collection {
            $endorsed = TransfereeCredit::query()
                ->where('student_id', $student->id)
                ->where('status', TransfereeCreditStatus::Endorsed->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($endorsed->isEmpty()) {
                return $endorsed;
            }

            $processedAt = now();

            foreach ($endorsed as $credit) {
                $credit->forceFill([
                    'status' => TransfereeCreditStatus::Approved,
                    'processed_by' => $head->id,
                    'processed_at' => $processedAt,
                ])->save();

                $this->auditRecorder->record(
                    $head,
                    AuditAction::TRANSFEREE_CREDIT_APPROVED,
                    AuditableType::TRANSFEREE_CREDIT,
                    $credit->id,
                    ['status' => TransfereeCreditStatus::Endorsed->value],
                    ['status' => TransfereeCreditStatus::Approved->value, 'subject_id' => $credit->subject_id],
                    null,
                    $context,
                );
            }

            $count = $endorsed->count();

            Notification::create([
                'user_id' => $student->user_id,
                'type' => NotificationType::TransfereeCreditApproved,
                'message' => $count === 1
                    ? 'Your transferee credit has been approved.'
                    : "{$count} of your transferee credits have been approved.",
            ]);

            // The approved credits count as completed, so the student's Regular/Irregular category may change.
            $this->reclassifyAfterApproval->execute($student, $head, $context);

            // Approving the last endorsed rows may close the student's credit mapping: they get their queue number.
            $this->issueQueueTicket->whenMappingCloses($student, $head, $context, $outbox);

            return $endorsed->load(['student.user', 'subject']);
        });
    }
}
