<?php

namespace App\Actions\Enrollment;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\EnrollmentChangeRequestStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Notifications\NotificationType;
use App\Models\Enrollment;
use App\Models\EnrollmentChangeRequest;
use App\Models\EnrollmentSubject;
use App\Models\Notification;
use App\Models\Section;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Phase 7: the Registrar Head approves or rejects a pending add/drop/
 * change-section request. Follows ADR 0011's shape (row lock,
 * required-current-status guard, `ValidationException` on mismatch)
 * established by `TransitionEnrollment`/`TransitionWithdrawalRequest`.
 *
 * `approve` is the one place this table touches `EnrollmentSubject`/
 * `sections.enrolled_count` — mirroring `SubmitEnrollment`'s own
 * lock-then-check-then-write shape: any section this approval would add a
 * seat to is locked and re-checked for a remaining seat here, authoritatively,
 * regardless of what the Form Request's pre-check saw at submission time.
 * A drop's seat release is unconditional (no config gate, unlike
 * `TransitionWithdrawalRequest`'s `enrollment.withdrawal.releases_seats` —
 * that gate exists for a §17-unconfirmed institutional policy specific to
 * full withdrawal; nothing in this feature's own design carries that same
 * ambiguity).
 */
final readonly class TransitionEnrollmentChangeRequest
{
    /**
     * @var array<string, EnrollmentChangeRequestStatus>
     */
    private const TARGET_STATUS = [
        'approve' => EnrollmentChangeRequestStatus::Approved,
        'reject' => EnrollmentChangeRequestStatus::Rejected,
    ];

    /**
     * @var array<string, string>
     */
    private const AUDIT_ACTION = [
        'approve' => AuditAction::ENROLLMENT_CHANGE_REQUEST_APPROVED,
        'reject' => AuditAction::ENROLLMENT_CHANGE_REQUEST_REJECTED,
    ];

    /**
     * @var array<string, NotificationType>
     */
    private const NOTIFICATION_TYPE = [
        'approve' => NotificationType::EnrollmentChangeRequestApproved,
        'reject' => NotificationType::EnrollmentChangeRequestRejected,
    ];

    private const REASON_REQUIRED_ACTIONS = ['reject'];

    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(
        EnrollmentChangeRequest $request,
        string $action,
        User $actor,
        ?string $reason,
        AuditRequestContext $context,
    ): EnrollmentChangeRequest {
        if (! isset(self::TARGET_STATUS[$action])) {
            throw new InvalidArgumentException('Unknown enrollment change request transition.');
        }

        if (
            in_array($action, self::REASON_REQUIRED_ACTIONS, true)
            && ($reason === null || trim($reason) === '')
        ) {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required for this action.',
            ]);
        }

        return DB::transaction(function () use ($request, $action, $actor, $reason, $context): EnrollmentChangeRequest {
            $lockedRequest = EnrollmentChangeRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequest->status !== EnrollmentChangeRequestStatus::Pending) {
                throw ValidationException::withMessages([
                    'action' => 'This action requires the request to currently be '.
                        "'pending'; it is currently '{$lockedRequest->status->value}'.",
                ]);
            }

            $lockedEnrollment = Enrollment::query()
                ->whereKey($lockedRequest->enrollment_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($action === 'approve' && $lockedEnrollment->status !== EnrollmentStatus::Enrolled) {
                throw ValidationException::withMessages([
                    'action' => 'This request cannot be approved because the enrollment is '.
                        "no longer 'enrolled' (currently '{$lockedEnrollment->status->value}').",
                ]);
            }

            $beforeValues = self::snapshot($lockedRequest);

            $lockedRequest->update([
                'status' => self::TARGET_STATUS[$action],
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_reason' => $reason,
            ]);
            $lockedRequest->refresh();

            if ($action === 'approve') {
                self::apply($lockedRequest);
            }

            $this->auditRecorder->record(
                $actor,
                self::AUDIT_ACTION[$action],
                AuditableType::ENROLLMENT_CHANGE_REQUEST,
                $lockedRequest->id,
                $beforeValues,
                self::snapshot($lockedRequest),
                in_array($action, self::REASON_REQUIRED_ACTIONS, true) ? $reason : null,
                $context,
            );

            Notification::create([
                'user_id' => $lockedEnrollment->student->user_id,
                'type' => self::NOTIFICATION_TYPE[$action],
                'message' => self::notificationMessage($action, $lockedRequest, $reason),
            ]);

            return $lockedRequest->refresh()->load(['enrollment.student', 'subject', 'fromSection', 'toSection']);
        });
    }

    /**
     * Locks whichever sections this approval touches, in ascending id order
     * (the same deadlock-avoidance discipline `SubmitEnrollment` uses),
     * authoritatively re-checks any section being added to for a remaining
     * seat, then writes the `EnrollmentSubject`/`enrolled_count` changes.
     */
    private static function apply(EnrollmentChangeRequest $request): void
    {
        $sectionIds = array_values(array_unique(array_filter([
            $request->from_section_id,
            $request->to_section_id,
        ])));
        sort($sectionIds);

        $sections = Section::query()
            ->whereIn('id', $sectionIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($request->to_section_id !== null) {
            $toSection = $sections->get($request->to_section_id);

            if ($toSection === null || $toSection->remainingSeats() < 1) {
                throw ValidationException::withMessages([
                    'to_section_id' => 'The target section no longer has an open seat. This request cannot be approved as-is.',
                ]);
            }
        }

        if ($request->from_section_id !== null) {
            EnrollmentSubject::query()
                ->where('enrollment_id', $request->enrollment_id)
                ->where('section_id', $request->from_section_id)
                ->where('status', '!=', EnrollmentSubjectStatus::Dropped->value)
                ->update(['status' => EnrollmentSubjectStatus::Dropped]);

            Section::query()->whereKey($request->from_section_id)->decrement('enrolled_count');
        }

        if ($request->to_section_id !== null) {
            EnrollmentSubject::create([
                'enrollment_id' => $request->enrollment_id,
                'section_id' => $request->to_section_id,
                'status' => EnrollmentSubjectStatus::Selected,
            ]);

            Section::query()->whereKey($request->to_section_id)->increment('enrolled_count');
        }
    }

    private static function notificationMessage(string $action, EnrollmentChangeRequest $request, ?string $reason): string
    {
        $typeLabel = $request->type->label();

        return match ($action) {
            'approve' => "Your {$typeLabel} request has been approved.",
            'reject' => "Your {$typeLabel} request was not approved. Reason: {$reason}",
            default => 'Your enrollment change request status has changed.',
        };
    }

    /**
     * @return array{type: string, status: string, subject_id: int, from_section_id: ?int, to_section_id: ?int}
     */
    private static function snapshot(EnrollmentChangeRequest $request): array
    {
        return [
            'type' => $request->type->value,
            'status' => $request->status->value,
            'subject_id' => $request->subject_id,
            'from_section_id' => $request->from_section_id,
            'to_section_id' => $request->to_section_id,
        ];
    }
}
