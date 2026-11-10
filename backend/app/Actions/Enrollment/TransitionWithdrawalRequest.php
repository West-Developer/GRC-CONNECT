<?php

namespace App\Actions\Enrollment;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Enrollment\WithdrawalStatus;
use App\Domain\Notifications\NotificationType;
use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\Notification;
use App\Models\Section;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * PRD §3.8 / FR-FIN-004: Registrar Staff approves or rejects a pending
 * withdrawal request. Follows ADR 0011's shape (row lock, required-current-
 * status guard, `ValidationException` on mismatch) established by
 * `TransitionScheduleProposal`/`TransitionEnrollment`.
 *
 * `approve` is the one transition in this codebase that releases a seat:
 * every `EnrollmentSubject` that still `occupiesSeat()` becomes `dropped`,
 * and — only when `config('enrollment.withdrawal.releases_seats')` is true
 * — each affected `Section.enrolled_count` is decremented exactly once.
 * Dropping the subjects is unconditional (it is simply correct that they
 * are no longer active); only the seat-count release itself is
 * config-gated, since §17 leaves that policy unconfirmed.
 *
 * Idempotency (the acceptance criterion "a withdrawal cannot decrement
 * section enrollment more than once"): `withdrawal_requests` has no unique
 * constraint on `enrollment_id`, so — beyond the ordinary
 * `REQUIRED_CURRENT_STATUS` guard on this request row — `approve` also
 * re-checks the enrollment is still `enrolled` under its own row lock
 * before touching it, so a second pending request against an
 * already-withdrawn enrollment cannot double-drop its seats.
 */
final readonly class TransitionWithdrawalRequest
{
    /**
     * @var array<string, WithdrawalStatus>
     */
    private const TARGET_STATUS = [
        'approve' => WithdrawalStatus::Approved,
        'reject' => WithdrawalStatus::Rejected,
    ];

    /**
     * @var array<string, string>
     */
    private const AUDIT_ACTION = [
        'approve' => AuditAction::WITHDRAWAL_REQUEST_APPROVED,
        'reject' => AuditAction::WITHDRAWAL_REQUEST_REJECTED,
    ];

    /**
     * @var array<string, NotificationType>
     */
    private const NOTIFICATION_TYPE = [
        'approve' => NotificationType::WithdrawalRequestApproved,
        'reject' => NotificationType::WithdrawalRequestRejected,
    ];

    private const REASON_REQUIRED_ACTIONS = ['reject'];

    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(
        WithdrawalRequest $withdrawalRequest,
        string $action,
        User $actor,
        ?string $reason,
        AuditRequestContext $context,
    ): WithdrawalRequest {
        if (! isset(self::TARGET_STATUS[$action])) {
            throw new InvalidArgumentException('Unknown withdrawal request transition.');
        }

        if (
            in_array($action, self::REASON_REQUIRED_ACTIONS, true)
            && ($reason === null || trim($reason) === '')
        ) {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required for this action.',
            ]);
        }

        return DB::transaction(function () use ($withdrawalRequest, $action, $actor, $reason, $context): WithdrawalRequest {
            $lockedRequest = WithdrawalRequest::query()
                ->whereKey($withdrawalRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequest->status !== WithdrawalStatus::Pending) {
                throw ValidationException::withMessages([
                    'action' => 'This action requires the withdrawal request to currently be '.
                        "'pending'; it is currently '{$lockedRequest->status->value}'.",
                ]);
            }

            $lockedEnrollment = Enrollment::query()
                ->whereKey($lockedRequest->enrollment_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($action === 'approve' && $lockedEnrollment->status !== EnrollmentStatus::Enrolled) {
                throw ValidationException::withMessages([
                    'action' => 'This withdrawal cannot be approved because the enrollment is '.
                        "no longer 'enrolled' (currently '{$lockedEnrollment->status->value}').",
                ]);
            }

            $beforeValues = self::snapshot($lockedRequest, $lockedEnrollment);

            $lockedRequest->update([
                'status' => self::TARGET_STATUS[$action],
                'processed_by' => $actor->id,
                'processed_at' => now(),
            ]);
            $lockedRequest->refresh();

            $droppedSectionIds = [];

            if ($action === 'approve') {
                $lockedEnrollment->update(['status' => EnrollmentStatus::Withdrawn]);
                $lockedEnrollment->refresh();

                $releaseSeats = (bool) config('enrollment.withdrawal.releases_seats', true);

                $seatOccupyingSubjects = EnrollmentSubject::query()
                    ->where('enrollment_id', $lockedEnrollment->id)
                    ->lockForUpdate()
                    ->get()
                    ->filter(fn (EnrollmentSubject $subject): bool => $subject->status->occupiesSeat());

                foreach ($seatOccupyingSubjects as $subject) {
                    $subject->update(['status' => EnrollmentSubjectStatus::Dropped]);

                    if ($releaseSeats) {
                        Section::query()->whereKey($subject->section_id)->decrement('enrolled_count');
                        $droppedSectionIds[] = $subject->section_id;
                    }
                }
            }

            $auditReason = in_array($action, self::REASON_REQUIRED_ACTIONS, true) ? $reason : null;

            $this->auditRecorder->record(
                $actor,
                self::AUDIT_ACTION[$action],
                AuditableType::WITHDRAWAL_REQUEST,
                $lockedRequest->id,
                $beforeValues,
                [
                    ...self::snapshot($lockedRequest, $lockedEnrollment),
                    'dropped_section_ids' => $droppedSectionIds,
                ],
                $auditReason,
                $context,
            );

            Notification::create([
                'user_id' => $lockedEnrollment->student->user_id,
                'type' => self::NOTIFICATION_TYPE[$action],
                'message' => self::notificationMessage($action, $reason),
            ]);

            return $lockedRequest->refresh()->load(['enrollment.student']);
        });
    }

    private static function notificationMessage(string $action, ?string $reason): string
    {
        return match ($action) {
            'approve' => 'Your withdrawal request has been approved. Your enrollment is now withdrawn.',
            'reject' => "Your withdrawal request was not approved. Reason: {$reason}",
            default => 'Your withdrawal request status has changed.',
        };
    }

    /**
     * @return array{status: string, enrollment_status: string}
     */
    private static function snapshot(WithdrawalRequest $request, Enrollment $enrollment): array
    {
        return [
            'status' => $request->status->value,
            'enrollment_status' => $enrollment->status->value,
        ];
    }
}
