<?php

namespace App\Domain\Organization;

/**
 * Institution-wide lifecycle for the manual enrollment-startup slice.
 * Per-college Process 1.1–1.3 progress lives in
 * `academic_term_college_workflows`, not in this enum.
 */
enum AcademicTermStatus: string
{
    case Draft = 'draft';
    case ForDeanApproval = 'for_dean_approval';
    case SemesterOngoing = 'semester_ongoing';
    case SemesterClosed = 'semester_closed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::ForDeanApproval => 'For Dean Approval',
            self::SemesterOngoing => 'Semester Ongoing',
            self::SemesterClosed => 'Semester Closed',
            self::Archived => 'Archived',
        };
    }

    /**
     * Whether learner-scoped roles (student, faculty, accounting staff) may
     * see this term. Every preparation/approval stage (through For Executive
     * Director Approval) is still being planned and is not yet learner-facing
     * — the same reasoning the original `Planning` case carried. From Ready
     * for Enrollment onward the term is either live or historical and
     * students need to see it — the same reasoning the original `Active`/
     * `Closed` cases carried. Planning roles always see every term regardless
     * of this value — see AcademicTerm::scopeVisibleTo().
     */
    public function isVisibleToLearners(): bool
    {
        return match ($this) {
            self::Draft,
            self::ForDeanApproval => false,
            self::SemesterOngoing,
            self::SemesterClosed,
            self::Archived => true,
        };
    }
}
