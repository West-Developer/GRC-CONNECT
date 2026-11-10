<?php

namespace App\Domain\Identity;

/**
 * PROVISIONAL VOCABULARY — NOT AN APPROVED INSTITUTIONAL POLICY VALUE.
 *
 * Whether a student entered the program as an incoming Freshman or a
 * Transferee from another institution — set once at admission provisioning
 * and shown to Admission/Registrar staff so they know who they are dealing
 * with. Informational only: it does not itself grant `TransfereeCredit`
 * rows or drive any eligibility/prerequisite logic — Registrar staff still
 * record transferee credits separately, subject by subject, via
 * `App\Actions\Academic\CreateTransfereeCredit`.
 */
enum StudentType: string
{
    case Freshman = 'freshman';
    case Transferee = 'transferee';
    case Returnee = 'returnee';
    /** Already a student of the school; Admission only needs to give them an account (stakeholder Doc 20). */
    case ExistingStudent = 'existing_student';

    public function label(): string
    {
        return match ($this) {
            self::Freshman => 'Freshman',
            self::Transferee => 'Transferee',
            self::Returnee => 'Returnee',
            self::ExistingStudent => 'Existing Student',
        };
    }

    /**
     * Whether Admission may enter a student number the student already has
     * instead of getting a new one (ADR 0042, amended 2026-10-04). A Returnee and
     * an Existing Student have records at the school, and a Transferee's number
     * from the school they came from is entered too (the owner's decision). Only
     * a Freshman has no number yet, so is always given a new one.
     */
    public function canHaveExistingStudentNumber(): bool
    {
        return $this !== self::Freshman;
    }

    /**
     * Whether the student gets a queue number and pays at the Cashier BEFORE the
     * Registrar approves their enrollment (ADR 0043, amended 2026-10-05): a
     * Freshman at Admission, a Transferee once their credit mapping closes. A
     * Returnee and an Existing Student follow the ordinary flow.
     */
    public function isPayFirst(): bool
    {
        return $this === self::Freshman || $this === self::Transferee;
    }

    /**
     * Whether a newly created account of this type has {@see ENROLLMENT_DEADLINE_DAYS} days to get its
     * enrollment approved by the Registrar, and sees only Enrollment until then (ADR 0048). A
     * Transferee and a Returnee both need credit mapping first, so both are held to it.
     */
    public function hasEnrollmentDeadline(): bool
    {
        return $this === self::Transferee || $this === self::Returnee;
    }

    public const ENROLLMENT_DEADLINE_DAYS = 7;
}
