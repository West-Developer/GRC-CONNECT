<?php

namespace App\Domain\Enrollment;

use App\Domain\Organization\AcademicTermStatus;
use Carbon\CarbonImmutable;

/**
 * Pure, database-free gate for whether the add/drop/change-section window is
 * open right now — same shape as `EnrollmentWindowResolver`, no Eloquent.
 *
 * Per the approved Phase 7 design, the window opens only once ALL of the
 * following hold: the term is `semester_ongoing`, the term-wide enrollment
 * window has already closed (`enrollment_closes_at`), and the Registrar's
 * own `add_drop_deadline_at` has not yet passed. `add_drop_deadline_at` has
 * existed as a column since the term-creation schema but carried zero
 * behavior before this resolver — this is the first thing that reads it.
 *
 * Both dates missing is treated as "not open" rather than "open forever":
 * `enrollment_closes_at` and `add_drop_deadline_at` are both `required` on
 * `StoreAcademicTermRequest`, so a null value only happens on a term
 * `ArchiveAndCreateNextTerm` just created (not yet configured) — silently
 * opening add/drop for an unconfigured term would be a worse failure mode
 * than blocking it.
 *
 * The optional `$studentAlreadyEnrolled` flag bypasses the
 * `EnrollmentStillOpen` check for students who are already enrolled in the
 * term. `StoreEnrollmentChangeRequestRequest` gates on
 * `EnrollmentStatus::Enrolled` before calling this, so passing `true` here
 * is safe: the student is definitively enrolled and the only relevant
 * constraints are whether the term is ongoing and whether the add/drop
 * deadline has passed.
 */
final class AddDropWindowResolver
{
    public static function resolve(
        AcademicTermStatus $status,
        ?CarbonImmutable $enrollmentClosesAt,
        ?CarbonImmutable $addDropDeadlineAt,
        CarbonImmutable $now,
        bool $studentAlreadyEnrolled = false,
        ?CarbonImmutable $addDropOpensAt = null,
    ): AddDropAvailability {
        $effectiveOpensAt = $addDropOpensAt ?? $enrollmentClosesAt;

        if ($status !== AcademicTermStatus::SemesterOngoing) {
            return new AddDropAvailability(
                false,
                AddDropAvailabilityReason::TermNotOngoing,
                $effectiveOpensAt,
                $addDropDeadlineAt,
            );
        }

        if ($addDropOpensAt !== null && $now->lt($addDropOpensAt)) {
            return new AddDropAvailability(
                false,
                AddDropAvailabilityReason::BeforeWindow,
                $effectiveOpensAt,
                $addDropDeadlineAt,
            );
        }

        // When the student is already enrolled and addDropOpensAt is not explicitly set,
        // skip the enrollment-window check: the student has already enrolled and only
        // needs the add/drop deadline to not have passed yet.
        if ($addDropOpensAt === null && ! $studentAlreadyEnrolled) {
            if ($enrollmentClosesAt === null || $now->lt($enrollmentClosesAt)) {
                return new AddDropAvailability(
                    false,
                    AddDropAvailabilityReason::EnrollmentStillOpen,
                    $effectiveOpensAt,
                    $addDropDeadlineAt,
                );
            }
        }

        if ($addDropDeadlineAt === null) {
            return new AddDropAvailability(
                false,
                AddDropAvailabilityReason::DeadlineNotConfigured,
                $effectiveOpensAt,
                $addDropDeadlineAt,
            );
        }

        if ($now->gt($addDropDeadlineAt)) {
            return new AddDropAvailability(
                false,
                AddDropAvailabilityReason::DeadlinePassed,
                $effectiveOpensAt,
                $addDropDeadlineAt,
            );
        }

        return new AddDropAvailability(
            true,
            AddDropAvailabilityReason::Open,
            $effectiveOpensAt,
            $addDropDeadlineAt,
        );
    }
}
