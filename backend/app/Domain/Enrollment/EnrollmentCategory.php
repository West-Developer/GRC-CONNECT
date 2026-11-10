<?php

namespace App\Domain\Enrollment;

/**
 * How a student enrols: with their year level's block, or subject by
 * subject during the irregular window.
 *
 * `student_profiles.enrollment_category`'s migration deliberately left this
 * an unconstrained nullable string because the regular/irregular taxonomy
 * was not yet an approved institutional rule. It is now — the enrollment
 * schedule has a dedicated irregular audience window, and irregular
 * students follow a different enrollment path — so the vocabulary is
 * pinned here. The column stays nullable: an unclassified student is
 * treated as regular by `EnrollmentAudience::forStudent()`.
 */
enum EnrollmentCategory: string
{
    case Regular = 'regular';
    case Irregular = 'irregular';

    public function label(): string
    {
        return match ($this) {
            self::Regular => 'Regular',
            self::Irregular => 'Irregular',
        };
    }
}
