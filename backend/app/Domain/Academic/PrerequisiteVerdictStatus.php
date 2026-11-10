<?php

namespace App\Domain\Academic;

/**
 * The three possible outcomes of comparing a student's recorded grade
 * against a curriculum's prerequisite threshold (FR-ENR-002, FR-ENR-005).
 *
 * `NeedsVerification` exists because PRD §17 leaves the official grading
 * policy unconfirmed: when config('enrollment.grading.comparison') is null,
 * or a recorded value cannot be compared, PrerequisiteEvaluator returns this
 * rather than guessing pass or fail. Never silently defaults to Satisfied.
 */
enum PrerequisiteVerdictStatus: string
{
    case Satisfied = 'satisfied';
    case NotSatisfied = 'not_satisfied';
    case NeedsVerification = 'needs_verification';

    public function label(): string
    {
        return match ($this) {
            self::Satisfied => 'Satisfied',
            self::NotSatisfied => 'Not satisfied',
            self::NeedsVerification => 'Needs verification',
        };
    }
}
