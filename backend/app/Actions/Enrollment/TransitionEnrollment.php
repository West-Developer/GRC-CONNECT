<?php

namespace App\Actions\Enrollment;

use App\Actions\Billing\AssessEnrollment;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\EnrollmentRouting;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Enrollment\QueueTicketStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Notifications\NotificationType;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\EnrollmentRevision;
use App\Models\EnrollmentSubject;
use App\Models\Notification;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Notifications\NotificationRecorder;
use App\Support\Notifications\ProgramChairRecipients;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Applies one Registrar Head decision to an enrollment (PRD §5.3 FR-FIN-001,
 * FR-FIN-002), following ADR 0011's shape exactly (see
 * `TransitionScheduleProposal`): a `TARGET_STATUS` / `REQUIRED_CURRENT_STATUS`
 * / `AUDIT_ACTION` constant trio, a row lock, and a `ValidationException` when
 * the current status doesn't match.
 *
 * Unlike `schedule_proposals`, the `enrollments` table has no `decided_by` or
 * `decision_reason` column of its own — only `registrar_decided_at`. The
 * reason required for `registrar_reject`/`void` (FR-FIN-002) is recorded
 * solely in the audit row's `reason` column, never on the enrollment itself.
 *
 * `registrar_approve` no longer issues a Cashier queue ticket — that moved
 * to `App\Actions\Enrollment\ClaimQueueTicket`, triggered by the student's
 * own claim (or Accounting Staff issuing one on their behalf) once they
 * are physically at the Cashier, matching the real front-desk process
 * (see docs/superpowers/specs/
 * 2026-08-23-queue-kiosk-claim-carryover-cutoff-design.md). This action
 * only opens the door: `pending_payment` plus an `Assessment`. The
 * Accounting-Staff-facing notification that used to fire here on approval
 * moved with it — `ClaimQueueTicket` fires it at the moment a student
 * actually joins the line, a more useful trigger than the moment they
 * merely become eligible to.
 *
 * Every transition into a terminal state (`rejected`, `cancelled`) gives the
 * enrollment's seats back exactly once: each subject still occupying a seat
 * is marked `dropped` and its section's `enrolled_count` decremented, under
 * the same row locks as the status change, so a repeat cannot release twice.
 * Before this, a rejected or voided enrollment kept its seats forever and a
 * student who re-enrolled counted twice. `student_cancel` (the student's own
 * undo, allowed until the Registrar approves) and `void` (Registrar Staff or
 * Head, any time before payment, ADR 0030) follow the same rule; a voided
 * enrollment's still-waiting queue ticket is cancelled with it.
 */
final class TransitionEnrollment
{
    /**
     * @var array<string, EnrollmentStatus>
     */
    private const TARGET_STATUS = [
        'program_head_approve' => EnrollmentStatus::PendingRegistrarApproval,
        'program_head_reject' => EnrollmentStatus::Rejected,
        'registrar_approve' => EnrollmentStatus::PendingPayment,
        'registrar_reject' => EnrollmentStatus::Rejected,
        'void' => EnrollmentStatus::Cancelled,
        'student_cancel' => EnrollmentStatus::Cancelled,
        // ADR 0040: the student's answer to the Program Chair's changes. Accepting
        // sends it straight to the Registrar; declining sends it back to the Chair.
        'student_accept_revision' => EnrollmentStatus::PendingRegistrarApproval,
        'student_decline_revision' => EnrollmentStatus::PendingProgramHeadApproval,
    ];

    /**
     * @var array<string, list<EnrollmentStatus>>
     */
    private const REQUIRED_CURRENT_STATUSES = [
        'program_head_approve' => [EnrollmentStatus::PendingProgramHeadApproval],
        'program_head_reject' => [EnrollmentStatus::PendingProgramHeadApproval],
        'registrar_approve' => [EnrollmentStatus::PendingRegistrarApproval],
        // The Registrar can also reject an already-approved enrollment that has not been paid
        // yet (`pending_payment`): the approvals page offers Reject there instead of Void.
        'registrar_reject' => [
            EnrollmentStatus::PendingRegistrarApproval,
            EnrollmentStatus::PendingPayment,
        ],
        // Before payment, at any approval stage. Once enrolled it is a withdrawal.
        'void' => [
            EnrollmentStatus::PendingProgramHeadApproval,
            EnrollmentStatus::PendingStudentReview,
            EnrollmentStatus::AwaitingFirstPayment,
            EnrollmentStatus::PendingRegistrarApproval,
            EnrollmentStatus::PendingPayment,
        ],
        // The student may undo their own choice only until the Registrar approves.
        'student_cancel' => [
            EnrollmentStatus::PendingProgramHeadApproval,
            EnrollmentStatus::PendingStudentReview,
            EnrollmentStatus::AwaitingFirstPayment,
            EnrollmentStatus::PendingRegistrarApproval,
        ],
        'student_accept_revision' => [EnrollmentStatus::PendingStudentReview],
        'student_decline_revision' => [EnrollmentStatus::PendingStudentReview],
    ];

    /**
     * @var array<string, string>
     */
    private const AUDIT_ACTION = [
        'program_head_approve' => AuditAction::ENROLLMENT_PROGRAM_HEAD_APPROVED,
        'program_head_reject' => AuditAction::ENROLLMENT_PROGRAM_HEAD_REJECTED,
        'registrar_approve' => AuditAction::ENROLLMENT_REGISTRAR_APPROVED,
        'registrar_reject' => AuditAction::ENROLLMENT_REGISTRAR_REJECTED,
        'void' => AuditAction::ENROLLMENT_VOIDED,
        'student_cancel' => AuditAction::ENROLLMENT_CANCELLED_BY_STUDENT,
        'student_accept_revision' => AuditAction::ENROLLMENT_STUDENT_ACCEPTED_REVISION,
        'student_decline_revision' => AuditAction::ENROLLMENT_STUDENT_DECLINED_REVISION,
    ];

    /**
     * `student_cancel` has no entry: the student just did it, so nobody is
     * notified.
     *
     * @var array<string, NotificationType>
     */
    private const NOTIFICATION_TYPE = [
        'program_head_approve' => NotificationType::EnrollmentProgramHeadApproved,
        'program_head_reject' => NotificationType::EnrollmentProgramHeadRejected,
        'registrar_approve' => NotificationType::EnrollmentRegistrarApproved,
        'registrar_reject' => NotificationType::EnrollmentRegistrarRejected,
        'void' => NotificationType::EnrollmentVoided,
    ];

    private const REASON_REQUIRED_ACTIONS = ['program_head_reject', 'registrar_reject', 'void', 'student_cancel', 'student_decline_revision'];

    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly AssessEnrollment $assessEnrollment,
        private readonly NotificationRecorder $notificationRecorder,
        private readonly SettleEnrollmentFromAdvanceCredit $settleFromAdvanceCredit,
    ) {}

    public function execute(
        Enrollment $enrollment,
        string $action,
        User $actingUser,
        ?string $reason,
        AuditRequestContext $context,
        bool $requestedByStudent = false,
        ?string $programHeadComment = null,
    ): Enrollment {
        if (! isset(self::TARGET_STATUS[$action])) {
            throw new InvalidArgumentException('Unknown enrollment transition.');
        }

        if (
            in_array($action, self::REASON_REQUIRED_ACTIONS, true)
            && ($reason === null || trim($reason) === '')
        ) {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required for this action.',
            ]);
        }

        return DB::transaction(function () use ($enrollment, $action, $actingUser, $reason, $context, $requestedByStudent, $programHeadComment): Enrollment {
            // Lock order is PROFILE, then ENROLLMENT, then sections: the order `ConfirmFreshmanPayment` and
            // `SubmitEnrollment` take. A review that routes by payment status therefore locks the student's
            // profile before the enrollment, so it cannot deadlock with the Cashier confirming that very
            // student's payment (the reverse order was a real InnoDB deadlock, found in review).
            $routesByPayment = in_array($action, ['program_head_approve', 'student_accept_revision'], true);
            // The Registrar's approval also takes the profile first: it ends a Transferee's or
            // Returnee's 7-day enrollment deadline (ADR 0048), and a profile write after the enrollment
            // lock would be the reverse of the order above.
            $lockedStudent = $routesByPayment || $action === 'registrar_approve'
                ? StudentProfile::query()->whereKey($enrollment->student_id)->lockForUpdate()->firstOrFail()
                : null;

            $lockedEnrollment = Enrollment::query()
                ->whereKey($enrollment->id)
                ->lockForUpdate()
                ->firstOrFail();
            $requiredStatuses = self::REQUIRED_CURRENT_STATUSES[$action];

            if (! in_array($lockedEnrollment->status, $requiredStatuses, true)) {
                $expected = implode("' or '", array_map(fn (EnrollmentStatus $status): string => $status->value, $requiredStatuses));

                throw ValidationException::withMessages([
                    'action' => "This action requires the enrollment to currently be '{$expected}'; ".
                        "it is currently '{$lockedEnrollment->status->value}'.",
                ]);
            }

            $beforeValues = self::snapshot($lockedEnrollment);

            // The Program Head's decision and the Registrar's are separate
            // moments; `void` is a Registrar-side decision too.
            // The student's own cancellation is a decision by nobody else, so it
            // stamps neither. A student accepting the Program Chair's changes
            // completes the Program Head stage (the Chair proposed them), so that
            // moment is stamped; declining leaves the stage open.
            $attributes = ['status' => $this->targetStatus($action, $routesByPayment ? $lockedStudent : null)];
            if (str_starts_with($action, 'program_head_') || $action === 'student_accept_revision') {
                $attributes['program_head_decided_at'] = now();
            } elseif (! in_array($action, ['student_cancel', 'student_decline_revision'], true)) {
                $attributes['registrar_decided_at'] = now();
            }
            if (str_starts_with($action, 'program_head_') && $programHeadComment !== null && trim($programHeadComment) !== '') {
                $attributes['program_head_comment'] = trim($programHeadComment);
            }

            $lockedEnrollment->update($attributes);
            $lockedEnrollment->refresh();

            // The 7-day deactivation (ADR 0048) does not end at the Registrar's approval any more: it ends
            // when the enrollment is finished and paid, in `ConfirmPayment` (owner, 2026-10-15). For a student
            // who paid first, the approval settles the payment and so reaches it in the same request.

            if ($action === 'student_accept_revision' || $action === 'student_decline_revision') {
                $this->answerRevision($lockedEnrollment, $action === 'student_accept_revision', $reason);
            }
            if (in_array($action, ['program_head_approve', 'program_head_reject'], true)) {
                EnrollmentRevision::query()
                    ->where('enrollment_id', $lockedEnrollment->id)
                    ->where('proposed_by', $lockedEnrollment->student->user_id)
                    ->where('status', EnrollmentRevision::STATUS_PENDING)
                    ->update([
                        'status' => $action === 'program_head_approve' ? EnrollmentRevision::STATUS_ACCEPTED : EnrollmentRevision::STATUS_DECLINED,
                        'responded_at' => now(),
                    ]);
            }

            $releasedSectionIds = $lockedEnrollment->status->isTerminal()
                ? $this->releaseSeats($lockedEnrollment)
                : [];
            // A rejection of an approved (pending payment) enrollment may follow a queue claim, so
            // it cancels the waiting ticket like a void does; before approval there is no ticket.
            if ($action === 'void' || $action === 'registrar_reject') {
                $this->cancelWaitingTicket($lockedEnrollment);
            }

            $afterValues = self::snapshot($lockedEnrollment);
            $auditReason = in_array($action, self::REASON_REQUIRED_ACTIONS, true) ? $reason : null;

            // PRD §5.3 process 3.3 "computes the approved assessment" --
            // done in the same transaction as the status change above, so
            // nothing may reach `pending_payment` without one. Folded into
            // this same audit row's after_values below, not a second row
            // -- see AssessEnrollment's own docblock for why.
            $assessment = $action === 'registrar_approve'
                ? $this->assessEnrollment->execute($lockedEnrollment)
                : null;

            // ADR 0043: a freshman who paid while every section was full holds that payment as
            // credit. When Registrar Staff approve the enrollment they made in the section that
            // opened, the held payment completes it — enrolled at once, no queue, no second payment.
            $settledFromCredit = $action === 'registrar_approve'
                && $this->settleFromAdvanceCredit->execute($lockedEnrollment, $actingUser, $context);
            if ($settledFromCredit) {
                $lockedEnrollment->refresh();
            }

            $this->auditRecorder->record(
                $actingUser,
                self::AUDIT_ACTION[$action],
                AuditableType::ENROLLMENT,
                $lockedEnrollment->id,
                $beforeValues,
                [
                    ...self::auditAfterValues($afterValues, $assessment),
                    'released_section_ids' => $releasedSectionIds,
                    ...($action === 'void' ? ['requested_by_student' => $requestedByStudent] : []),
                ],
                $auditReason,
                $context,
            );

            if (isset(self::NOTIFICATION_TYPE[$action])) {
                Notification::create([
                    'user_id' => $lockedEnrollment->student->user_id,
                    'type' => self::NOTIFICATION_TYPE[$action],
                    'message' => $settledFromCredit
                        ? 'Your enrollment has been approved by the Registrar and your earlier payment was applied. You are now enrolled; there is no queue ticket or second payment to make.'
                        : self::notificationMessage($action, $lockedEnrollment, $reason),
                ]);
            }

            // A transferee who has not paid is at the payment hold: the Registrar is not told
            // until the Cashier has taken the first payment.
            $atPaymentHold = $lockedEnrollment->status === EnrollmentStatus::AwaitingFirstPayment;

            if ($action === 'student_accept_revision') {
                $student = $lockedEnrollment->student;
                $this->notificationRecorder->recordMany(
                    ProgramChairRecipients::forStudent($student),
                    NotificationType::EnrollmentRevisionAccepted,
                    $atPaymentHold
                        ? sprintf('Student %s accepted your changes to their subjects. The enrollment now waits for their first payment at the Cashier.', $student->student_number)
                        : sprintf('Student %s accepted your changes to their subjects. The enrollment now goes to the Registrar.', $student->student_number),
                );

                if (! $atPaymentHold) {
                    $this->notificationRecorder->recordManyForRoles(
                        [UserRole::RegistrarStaff, UserRole::RegistrarHead],
                        NotificationType::EnrollmentProgramHeadApproved,
                        sprintf(
                            "Student %s accepted the Program Chair's changes to their subjects; the enrollment now awaits Registrar approval.",
                            $student->student_number,
                        ),
                    );
                }
            }

            if ($action === 'student_decline_revision') {
                $student = $lockedEnrollment->student;
                $this->notificationRecorder->recordMany(
                    ProgramChairRecipients::forStudent($student),
                    NotificationType::EnrollmentRevisionDeclined,
                    sprintf('Student %s did not accept your changes to their subjects. Reason: %s', $student->student_number, trim((string) $reason)),
                );
            }

            // The Program Head stage is done, so the enrollment now waits on the Registrar
            // (or, for a transferee who has not paid, on the Cashier first).
            if ($action === 'program_head_approve' && ! $atPaymentHold) {
                $this->notificationRecorder->recordManyForRoles(
                    [UserRole::RegistrarStaff, UserRole::RegistrarHead],
                    NotificationType::EnrollmentProgramHeadApproved,
                    sprintf(
                        'Student %s: the Program Head approved the enrollment schedule; it now awaits Registrar approval.',
                        $lockedEnrollment->student->student_number,
                    ),
                );
            }

            return $lockedEnrollment->refresh()->load([
                'student', 'enrollmentSubjects.section.subject', 'enrollmentSubjects.section.professor', 'queueTicket', 'assessment.items', 'revisions',
            ]);
        });
    }

    /**
     * Where an action sends the enrollment. Passing review (`program_head_approve`, or the student
     * accepting the Chair's changes) goes to the Registrar, except for a transferee who has not paid:
     * `EnrollmentRouting` sends them to the payment hold. `$lockedStudent` is the profile row, locked by
     * the caller BEFORE the enrollment (the same lock `ConfirmFreshmanPayment` takes first), so a payment
     * confirmed at this very moment is seen either way; it is null for every other action.
     */
    private function targetStatus(string $action, ?StudentProfile $lockedStudent): EnrollmentStatus
    {
        if ($lockedStudent === null) {
            return self::TARGET_STATUS[$action];
        }

        return EnrollmentRouting::statusAfterReview($lockedStudent);
    }

    /**
     * Records the student's answer on the Program Chair's latest open proposal
     * (ADR 0040): accepted, or declined with the student's reason.
     */
    private function answerRevision(Enrollment $enrollment, bool $accepted, ?string $reason): void
    {
        $revision = EnrollmentRevision::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('status', EnrollmentRevision::STATUS_PENDING)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $revision?->update([
            'status' => $accepted ? EnrollmentRevision::STATUS_ACCEPTED : EnrollmentRevision::STATUS_DECLINED,
            'student_reason' => $accepted ? null : trim((string) $reason),
            'responded_at' => now(),
        ]);
    }

    /**
     * Marks every subject still holding a seat `dropped` and gives the seat
     * back, once. The rows are locked first, so a concurrent transition sees
     * them already dropped.
     *
     * @return list<int> the section ids whose seat was released
     */
    private function releaseSeats(Enrollment $enrollment): array
    {
        $released = [];

        EnrollmentSubject::query()
            ->where('enrollment_id', $enrollment->id)
            ->lockForUpdate()
            ->get()
            ->filter(fn (EnrollmentSubject $subject): bool => $subject->status->occupiesSeat())
            ->each(function (EnrollmentSubject $subject) use (&$released): void {
                $subject->update(['status' => EnrollmentSubjectStatus::Dropped]);
                Section::query()->whereKey($subject->section_id)->where('enrolled_count', '>', 0)->decrement('enrolled_count');
                $released[] = $subject->section_id;
            });

        return $released;
    }

    private function cancelWaitingTicket(Enrollment $enrollment): void
    {
        $enrollment->queueTicket()
            ->where('status', QueueTicketStatus::Waiting->value)
            ->update(['status' => QueueTicketStatus::Cancelled->value]);
    }

    /**
     * @param  array{student_id: int, academic_term_id: int, status: string, program_head_decided_at: ?string, registrar_decided_at: ?string, program_head_comment: ?string}  $afterValues
     * @return array<string, mixed>
     */
    private static function auditAfterValues(array $afterValues, ?Assessment $assessment): array
    {
        if ($assessment !== null) {
            $afterValues = [...$afterValues, 'assessment_total_amount' => $assessment->total_amount, 'assessment_item_count' => $assessment->items->count()];
        }

        return $afterValues;
    }

    private static function notificationMessage(string $action, Enrollment $enrollment, ?string $reason): string
    {
        $comment = $enrollment->program_head_comment;
        $commentSuffix = $comment !== null ? " Program Head note: {$comment}" : '';

        return match ($action) {
            'program_head_approve' => $enrollment->status === EnrollmentStatus::AwaitingFirstPayment
                ? "Your Program Head approved your enrollment schedule. Take your queue number to the Cashier and make your first payment; the Registrar approves once you have paid.{$commentSuffix}"
                : "Your Program Head approved your enrollment schedule. It now waits for Registrar approval.{$commentSuffix}",
            'program_head_reject' => "Your enrollment was not approved by your Program Head. Reason: {$reason}{$commentSuffix}",
            'registrar_approve' => 'Your enrollment has been approved by the Registrar and is now pending payment. Visit the Cashier to claim your queue ticket.',
            'registrar_reject' => "Your enrollment was rejected by the Registrar. Reason: {$reason}",
            'void' => "Your enrollment has been voided by the Registrar. Reason: {$reason}",
            default => 'Your enrollment status has changed.',
        };
    }

    /**
     * @return array{student_id: int, academic_term_id: int, status: string, program_head_decided_at: ?string, registrar_decided_at: ?string, program_head_comment: ?string}
     */
    private static function snapshot(Enrollment $enrollment): array
    {
        return [
            'student_id' => $enrollment->student_id,
            'academic_term_id' => $enrollment->academic_term_id,
            'status' => $enrollment->status->value,
            'program_head_decided_at' => $enrollment->program_head_decided_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'registrar_decided_at' => $enrollment->registrar_decided_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'program_head_comment' => $enrollment->program_head_comment,
        ];
    }
}
