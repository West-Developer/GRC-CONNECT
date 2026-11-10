<?php

namespace App\Actions\Enrollment;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\WithdrawalStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Notifications\NotificationType;
use App\Models\Enrollment;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\Audit\AuditRecorder;
use App\Support\Notifications\NotificationRecorder;
use Illuminate\Support\Facades\DB;

/**
 * PRD §4.2 rule 7 / FR-FIN-004: a Student requests withdrawal from their own
 * `enrolled` enrollment. This only records the request as `pending` — it
 * does not touch the enrollment or release any seat; that happens only on
 * `TransitionWithdrawalRequest`'s `approve`, since Registrar Staff decides
 * whether the withdrawal is granted (PRD §3.8).
 */
final readonly class RequestWithdrawal
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private NotificationRecorder $notificationRecorder,
    ) {}

    public function execute(
        Enrollment $enrollment,
        string $reason,
        User $actor,
        AuditRequestContext $context,
    ): WithdrawalRequest {
        return DB::transaction(function () use ($enrollment, $reason, $actor, $context): WithdrawalRequest {
            $request = WithdrawalRequest::create([
                'enrollment_id' => $enrollment->id,
                'reason' => $reason,
                'status' => WithdrawalStatus::Pending,
            ]);

            $this->auditRecorder->record(
                $actor,
                AuditAction::WITHDRAWAL_REQUEST_CREATED,
                AuditableType::WITHDRAWAL_REQUEST,
                $request->id,
                null,
                [
                    'enrollment_id' => $enrollment->id,
                    'status' => $request->status->value,
                ],
                null,
                $context,
            );

            // Registrar Staff and the Registrar Head both decide withdrawals (ADR 0030 amendment, S07).
            $this->notificationRecorder->recordManyForRoles(
                [UserRole::RegistrarStaff, UserRole::RegistrarHead],
                NotificationType::WithdrawalRequestSubmitted,
                "{$enrollment->student->student_number} submitted a withdrawal request.",
            );

            return $request->refresh()->load('enrollment.student');
        });
    }
}
