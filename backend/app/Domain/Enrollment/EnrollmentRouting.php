<?php

namespace App\Domain\Enrollment;

use App\Domain\Identity\StudentType;
use App\Models\StudentProfile;

/**
 * Where an enrollment goes once nothing is left to review before the Registrar
 * (ADR 0043, amended 2026-10-05): a regular submission straight away, an
 * Irregular or overload one after the Program Chair has approved it (ADR 0030)
 * or the student has accepted the Chair's changes (ADR 0040).
 *
 * A transferee who has not paid yet waits at `awaiting_first_payment`, so the
 * Registrar never sees an enrollment that has not been paid; once they have paid
 * (held credit, no enrollment at the time) it goes straight to the Registrar and
 * settles from that credit. Every other student goes straight to the Registrar.
 */
final class EnrollmentRouting
{
    public static function statusAfterReview(StudentProfile $student): EnrollmentStatus
    {
        return $student->student_type === StudentType::Transferee && $student->freshman_payment_confirmed_at === null
            ? EnrollmentStatus::AwaitingFirstPayment
            : EnrollmentStatus::PendingRegistrarApproval;
    }
}
