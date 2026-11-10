<?php

namespace App\Domain\Enrollment;

use App\Domain\Organization\AcademicTermStatus;
use Carbon\CarbonImmutable;

/**
 * Pure, database-free gate for whether a student may submit an enrollment
 * right now — no Eloquent, so this stays unit-testable regardless of the
 * local database's migration state.
 *
 * Per ADR 0018 ("no date alone silently changes lifecycle status"), a term
 * must first be explicitly transitioned to `semester_ongoing` by the
 * Registrar Head (see `TransitionAcademicTerm`'s `open_enrollment` action)
 * before any date is even considered. Once the term is open, each
 * `EnrollmentAudience` (year_1..4, irregular) has its own effective
 * open/close window, falling back to the term-wide
 * `enrollment_opens_at`/`enrollment_closes_at` when that audience has no
 * override — which in practice is only when its
 * `academic_term_enrollment_windows` row is missing entirely (a term
 * created before that table existed), since `CreateAcademicTerm` otherwise
 * always seeds one row per audience.
 */
final class EnrollmentWindowResolver
{
    public static function resolve(
        AcademicTermStatus $status,
        ?CarbonImmutable $termOpensAt,
        ?CarbonImmutable $termClosesAt,
        ?CarbonImmutable $audienceOpensAt,
        ?CarbonImmutable $audienceClosesAt,
        CarbonImmutable $now,
    ): EnrollmentAvailability {
        if ($status !== AcademicTermStatus::SemesterOngoing) {
            $reason = match ($status) {
                AcademicTermStatus::SemesterClosed,
                AcademicTermStatus::Archived => EnrollmentAvailabilityReason::TermClosed,
                AcademicTermStatus::Draft,
                AcademicTermStatus::ForDeanApproval => EnrollmentAvailabilityReason::TermNotOpen,
            };

            return new EnrollmentAvailability(false, $reason, $termOpensAt, $termClosesAt);
        }

        $effectiveOpensAt = $audienceOpensAt ?? $termOpensAt;
        $effectiveClosesAt = $audienceClosesAt ?? $termClosesAt;

        if ($effectiveOpensAt !== null && $now->lt($effectiveOpensAt)) {
            return new EnrollmentAvailability(
                false,
                EnrollmentAvailabilityReason::BeforeWindow,
                $effectiveOpensAt,
                $effectiveClosesAt,
            );
        }

        if ($effectiveClosesAt !== null && $now->gt($effectiveClosesAt)) {
            return new EnrollmentAvailability(
                false,
                EnrollmentAvailabilityReason::AfterWindow,
                $effectiveOpensAt,
                $effectiveClosesAt,
            );
        }

        return new EnrollmentAvailability(
            true,
            EnrollmentAvailabilityReason::Open,
            $effectiveOpensAt,
            $effectiveClosesAt,
        );
    }
}
