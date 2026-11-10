<?php

namespace App\Domain\Curriculum;

/**
 * A curriculum placement's semester slot within a year level. Distinct from
 * `AcademicTerm::$semester` (a plain `'1st'|'2nd'` string on a real term
 * row) — this is the curriculum-plan side, consumed by `SemesterCoverage`
 * and the prospectus/classifier actions that walk a curriculum in order.
 */
enum SemesterSlot: string
{
    case First = '1st';
    case Second = '2nd';

    public function label(): string
    {
        return match ($this) {
            self::First => '1st Semester',
            self::Second => '2nd Semester',
        };
    }

    /**
     * A stable, comparable ordinal across the whole 4-year plan — year 1
     * 1st sem is 1, year 1 2nd sem is 2, year 2 1st sem is 3, and so on.
     * Used to decide whether a placement's semester has already "passed"
     * relative to a student's current position.
     */
    public function ordinal(int $yearLevel): int
    {
        return ($yearLevel - 1) * 2 + ($this === self::First ? 1 : 2);
    }
}
