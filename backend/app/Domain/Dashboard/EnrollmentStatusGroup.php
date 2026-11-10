<?php

namespace App\Domain\Dashboard;

use App\Domain\Enrollment\EnrollmentStatus;

/**
 * The four buckets the Enrollment Dashboard sorts every student into for one
 * term. Derived only from `EnrollmentStatus` (PRD §4.2, authoritative) and
 * from whether the student has an enrollment row at all — never from a
 * provisional vocabulary — so ADR 0017's enum-driven grouping rule holds.
 *
 *   enrolled      — the student's current enrollment is `enrolled`.
 *   in_progress   — `draft`, `pending_program_head_approval`,
 *                   `pending_student_review`, `awaiting_first_payment`,
 *                   `pending_registrar_approval` or `pending_payment`.
 *   not_enrolled  — the student started but ended without enrolling:
 *                   `rejected`, `cancelled` or `withdrawn`.
 *   not_yet_done  — an eligible student with no enrollment row this term.
 */
enum EnrollmentStatusGroup: string
{
    case Enrolled = 'enrolled';
    case InProgress = 'in_progress';
    case NotYetDone = 'not_yet_done';
    case NotEnrolled = 'not_enrolled';

    public function label(): string
    {
        return match ($this) {
            self::Enrolled => 'Enrolled',
            self::InProgress => 'Ongoing',
            self::NotYetDone => 'Not yet done',
            self::NotEnrolled => 'Not enrolled',
        };
    }

    /**
     * The enrollment statuses that place a student in this group. Empty for
     * `not_yet_done`, which by definition has no enrollment row.
     *
     * @return list<EnrollmentStatus>
     */
    public function statuses(): array
    {
        return match ($this) {
            self::Enrolled => [EnrollmentStatus::Enrolled],
            self::InProgress => [
                EnrollmentStatus::Draft,
                EnrollmentStatus::PendingProgramHeadApproval,
                EnrollmentStatus::PendingStudentReview,
                EnrollmentStatus::AwaitingFirstPayment,
                EnrollmentStatus::PendingRegistrarApproval,
                EnrollmentStatus::PendingPayment,
            ],
            self::NotEnrolled => [
                EnrollmentStatus::Rejected,
                EnrollmentStatus::Cancelled,
                EnrollmentStatus::Withdrawn,
            ],
            self::NotYetDone => [],
        };
    }

    public static function forStatus(?EnrollmentStatus $status): self
    {
        if ($status === null) {
            return self::NotYetDone;
        }

        foreach (self::cases() as $group) {
            if (in_array($status, $group->statuses(), true)) {
                return $group;
            }
        }

        return self::NotYetDone;
    }

    /**
     * A zero-filled map keyed by every group value, in display order.
     *
     * @return array<string, int>
     */
    public static function emptyCounts(): array
    {
        $counts = [];
        foreach (self::cases() as $group) {
            $counts[$group->value] = 0;
        }

        return $counts;
    }
}
