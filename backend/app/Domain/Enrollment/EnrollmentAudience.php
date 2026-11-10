<?php

namespace App\Domain\Enrollment;

use InvalidArgumentException;

/**
 * The five populations an academic term's enrollment schedule is staggered
 * by: one per year level (1st-4th), plus a fifth "irregular" audience that
 * replaces the year-level window entirely for a student whose
 * `student_profiles.enrollment_category` is irregular. During a year-level
 * window, block-exclusive sections are available to that audience; the
 * irregular window is when an irregular student — excluded from
 * block-exclusive seats by `BuildEligibleSubjectPool::isBlockRestricted()`
 * — enrolls instead, choosing freely from non-block sections.
 */
enum EnrollmentAudience: string
{
    case Year1 = 'year_1';
    case Year2 = 'year_2';
    case Year3 = 'year_3';
    case Year4 = 'year_4';
    case Irregular = 'irregular';
    case LateEnrollee = 'late_enrollee';

    /**
     * The `student_profiles.enrollment_category` value meaning "irregular".
     * Derived from `EnrollmentCategory` so the vocabulary has exactly one
     * definition, while existing callers of this constant keep working.
     */
    public const IRREGULAR_CATEGORY = EnrollmentCategory::Irregular->value;

    public function label(): string
    {
        return match ($this) {
            self::Year1 => '1st Year',
            self::Year2 => '2nd Year',
            self::Year3 => '3rd Year',
            self::Year4 => '4th Year',
            self::Irregular => 'Irregular Students',
            self::LateEnrollee => 'Late Enrollees',
        };
    }

    /**
     * Null for Irregular and Late Enrollees — they are not tied to a single year level.
     */
    public function yearLevel(): ?int
    {
        return match ($this) {
            self::Year1 => 1,
            self::Year2 => 2,
            self::Year3 => 3,
            self::Year4 => 4,
            self::Irregular, self::LateEnrollee => null,
        };
    }

    public static function fromYearLevel(int $yearLevel): self
    {
        return match ($yearLevel) {
            1 => self::Year1,
            2 => self::Year2,
            3 => self::Year3,
            4 => self::Year4,
            default => throw new InvalidArgumentException("Unsupported year level: {$yearLevel}"),
        };
    }

    /**
     * Which audience governs a given student's enrollment window: their
     * year level, unless their enrollment category is irregular, in which
     * case the irregular window applies regardless of year level.
     */
    public static function forStudent(?string $enrollmentCategory, int $yearLevel): self
    {
        if ($enrollmentCategory !== null && strtolower($enrollmentCategory) === self::IRREGULAR_CATEGORY) {
            return self::Irregular;
        }

        return self::fromYearLevel($yearLevel);
    }
}
