<?php

namespace App\Domain\Enrollment;

use App\Domain\Organization\AcademicTermStatus;
use Carbon\CarbonImmutable;

/**
 * Whether a Student may see who teaches a section yet (stakeholder Doc 14).
 *
 * Students choose sections without knowing the professor, so the choice is
 * about time and subject rather than a person. The professor is revealed once
 * both the enrollment window and the add/drop window are over: after the later
 * of `enrollment_closes_at` and `add_drop_deadline_at`. A closed or archived
 * term is always revealed. When neither date is configured the professor stays
 * hidden until the term is closed, the same fail-closed reading
 * `AddDropWindowResolver` takes for a missing deadline.
 *
 * Pure and database-free, like the other resolvers in this folder. This
 * decides only the Student view: every other role sees the professor as
 * before, and the API (not the UI) is what withholds it.
 */
final class ProfessorVisibility
{
    public static function hiddenFromStudents(
        AcademicTermStatus $status,
        ?CarbonImmutable $enrollmentClosesAt,
        ?CarbonImmutable $addDropDeadlineAt,
        CarbonImmutable $now,
    ): bool {
        if ($status === AcademicTermStatus::SemesterClosed || $status === AcademicTermStatus::Archived) {
            return false;
        }

        $revealedAfter = collect([$enrollmentClosesAt, $addDropDeadlineAt])
            ->filter()
            ->max();

        if (! $revealedAfter instanceof CarbonImmutable) {
            return true;
        }

        return $now->lte($revealedAfter);
    }
}
