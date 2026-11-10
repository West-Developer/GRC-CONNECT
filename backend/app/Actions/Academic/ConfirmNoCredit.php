<?php

namespace App\Actions\Academic;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\QueueLinkOutbox;
use App\Domain\Notifications\NotificationType;
use App\Models\NoCreditDeclaration;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Notifications\NotificationRecorder;
use Illuminate\Support\Facades\DB;

/**
 * The Program Chair confirms that a student has no subject to credit (ADR 0026 / 0050, amended): the credit
 * mapping is complete with no credit rows, so the student may enroll, and a transferee, who pays first, is
 * given their queue number in the same transaction (`IssueTransfereeQueueTicket`). The Registrar Head has
 * nothing to approve, because no credit was claimed.
 *
 * Idempotent: the declaration row is locked, and a declaration that is already confirmed is returned
 * unchanged, so a double tap or a second Chair confirms once, audits once and notifies once.
 */
final readonly class ConfirmNoCredit
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private NotificationRecorder $notificationRecorder,
        private IssueTransfereeQueueTicket $issueQueueTicket,
    ) {}

    public function execute(
        NoCreditDeclaration $declaration,
        User $actor,
        AuditRequestContext $context,
        ?QueueLinkOutbox $outbox = null,
    ): NoCreditDeclaration {
        return DB::transaction(function () use ($declaration, $actor, $context, $outbox): NoCreditDeclaration {
            $locked = NoCreditDeclaration::query()->whereKey($declaration->id)->lockForUpdate()->firstOrFail();

            if ($locked->isConfirmed()) {
                return $locked->load('student.user');
            }

            $locked->forceFill([
                'confirmed_at' => now(),
                'confirmed_by_user_id' => $actor->id,
            ])->save();

            $student = StudentProfile::query()->findOrFail($locked->student_id);

            $this->auditRecorder->record(
                $actor,
                AuditAction::NO_CREDIT_CONFIRMED,
                AuditableType::STUDENT_PROFILE,
                $student->id,
                ['confirmed' => false],
                ['confirmed' => true],
                null,
                $context,
            );

            $this->notificationRecorder->recordMany(
                [$student->user_id],
                NotificationType::TransfereeNoCreditConfirmed,
                'Your Program Head confirmed that you have no subject to credit, so you can go on to enroll.',
            );

            $this->issueQueueTicket->whenMappingCloses($student, $actor, $context, $outbox);

            return $locked->load('student.user');
        });
    }
}
