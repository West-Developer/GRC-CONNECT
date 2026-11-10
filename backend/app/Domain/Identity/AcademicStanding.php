<?php

namespace App\Domain\Identity;

/**
 * PROVISIONAL VOCABULARY — NOT AN APPROVED INSTITUTIONAL POLICY VALUE.
 *
 * PRD §10.1 requires an `academic_standing` on the student profile but does not
 * enumerate its values. The rules that would *derive* standing (passing-grade
 * rule, honors cutoff, disqualifying grades) are all open §17 decisions, so
 * nothing in this slice computes standing — it is stored data only.
 */
enum AcademicStanding: string
{
    case Good = 'good';
    case Probation = 'probation';
    case Warning = 'warning';

    public function label(): string
    {
        return match ($this) {
            self::Good => 'Good Standing',
            self::Probation => 'Probation',
            self::Warning => 'Warning',
        };
    }
}
