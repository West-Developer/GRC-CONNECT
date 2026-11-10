<?php

namespace App\Domain\Enrollment;

/**
 * The enrollment lifecycle defined in PRD §4.2.
 *
 * Unlike the §17 status vocabularies, these values ARE specified by the PRD:
 *
 *   draft → pending_registrar_approval → pending_payment → enrolled
 *
 * with `rejected`, `cancelled`, and `withdrawn` as terminal exception states.
 * An irregular or overload submission first waits at
 * `pending_program_head_approval` and only then joins the Registrar's queue
 * (ADR 0030, stakeholder Doc 14); a regular submission goes straight to
 * `pending_registrar_approval`. When the Program Chair changes the subjects the
 * student chose, the enrollment waits at `pending_student_review` until the
 * student accepts (it then goes straight to the Registrar) or declines with a
 * reason (it returns to the Program Chair), ADR 0040.
 * Treat this enum as authoritative and change it only when the PRD changes.
 *
 * The `enrollments.active_academic_term_id` generated column repeats the
 * terminal-state list in SQL to enforce "one active enrollment per student and
 * term" in the database. Any change here MUST ship with a migration that
 * rebuilds that column, or the two definitions will silently disagree.
 */
enum EnrollmentStatus: string
{
    case Draft = 'draft';
    case PendingProgramHeadApproval = 'pending_program_head_approval';
    case PendingStudentReview = 'pending_student_review';
    /** A transferee's chosen subjects wait here for the Cashier's first payment, before the Registrar sees them (ADR 0043, amended). */
    case AwaitingFirstPayment = 'awaiting_first_payment';
    case PendingRegistrarApproval = 'pending_registrar_approval';
    case PendingPayment = 'pending_payment';
    case Enrolled = 'enrolled';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingProgramHeadApproval => 'Pending Program Head Approval',
            self::PendingStudentReview => 'Awaiting Student Review',
            self::AwaitingFirstPayment => 'Awaiting First Payment',
            self::PendingRegistrarApproval => 'Pending Registrar Approval',
            self::PendingPayment => 'Pending Payment',
            self::Enrolled => 'Enrolled',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
            self::Withdrawn => 'Withdrawn',
        };
    }

    /**
     * A terminal state releases the student's seat for the term, which is what
     * lets a student re-enroll after a cancellation or withdrawal without
     * tripping the unique active-enrollment constraint.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Rejected, self::Cancelled, self::Withdrawn => true,
            self::Draft,
            self::PendingProgramHeadApproval,
            self::PendingStudentReview,
            self::AwaitingFirstPayment,
            self::PendingRegistrarApproval,
            self::PendingPayment,
            self::Enrolled => false,
        };
    }

    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * The terminal values as raw strings, for the SQL generated-column
     * expression and for query scopes.
     *
     * @return list<string>
     */
    public static function terminalValues(): array
    {
        return array_values(array_map(
            fn (self $status): string => $status->value,
            array_filter(self::cases(), fn (self $status): bool => $status->isTerminal()),
        ));
    }
}
