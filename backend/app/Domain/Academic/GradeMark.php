<?php

namespace App\Domain\Academic;

/**
 * GRC's grading scale (user direction, 2026-08-04 — see PRD §17: the
 * official passing-grade rule remains an open institutional decision, so
 * this enum is demonstrable-scale, not yet signed off). Descending numeric
 * scale — 1.00 is the highest mark, 5.00 is failed — plus four non-numeric
 * marks: `C`/`NC` (used exclusively by completion-only subjects, see
 * `CompletionOnlySubjectRule`), `INC` (Incomplete), `DRP` (Dropped), and `W` (Withdrawn). `W` is entered only
 * by the Registrar Head's subject withdrawal (`WithdrawEnrolledSubjects`) and
 * is never accepted from grade encoding.
 *
 * `academic_grades.final_grade` mirrors this enum's `numericValue()` — NULL
 * for every non-numeric mark. That mirroring is maintained by the actions
 * that write grades (`RecordAcademicGrade`, `UpdateAcademicGrade`), not by
 * this enum.
 */
enum GradeMark: string
{
    case Excellent = '1.00';
    case HighDistinction = '1.25';
    case WithDistinction = '1.50';
    case VeryGood = '1.75';
    case Good = '2.00';
    case VerySatisfactory = '2.25';
    case Satisfactory = '2.50';
    case Fair = '2.75';
    case Passed = '3.00';
    case Failed = '5.00';
    case Complete = 'C';
    case NotComplete = 'NC';
    case Incomplete = 'INC';
    case Dropped = 'DRP';
    case Withdrawn = 'W';

    public function label(): string
    {
        return match ($this) {
            self::Excellent => 'Excellent',
            self::HighDistinction => 'High Distinction',
            self::WithDistinction => 'with Distinction',
            self::VeryGood => 'Very Good',
            self::Good => 'Good',
            self::VerySatisfactory => 'Very Satisfactory',
            self::Satisfactory => 'Satisfactory',
            self::Fair => 'Fair',
            self::Passed => 'Passed',
            self::Failed => 'Failed',
            self::Complete => 'Complete',
            self::NotComplete => 'Not Complete',
            self::Incomplete => 'Incomplete',
            self::Dropped => 'Dropped',
            self::Withdrawn => 'Withdrawn',
        };
    }

    public function isNumeric(): bool
    {
        return $this->numericValue() !== null;
    }

    public function numericValue(): ?float
    {
        return match ($this) {
            self::Excellent, self::HighDistinction, self::WithDistinction,
            self::VeryGood, self::Good, self::VerySatisfactory,
            self::Satisfactory, self::Fair, self::Passed, self::Failed => (float) $this->value,
            self::Complete, self::NotComplete, self::Incomplete, self::Dropped, self::Withdrawn => null,
        };
    }

    /**
     * Deliberately hard-codes 3.00 rather than reading
     * config('enrollment.grading.passing_grade') — this enum stays pure and
     * config-free, the same doctrine PrerequisiteEvaluator already follows.
     * GradeMarkTest asserts this stays in sync with the config default.
     */
    public function isPassing(): bool
    {
        return $this === self::Complete || ($this->isNumeric() && (float) $this->value <= 3.00);
    }

    public function isFailing(): bool
    {
        return $this === self::Failed;
    }

    /**
     * True only for `C` — the mark that satisfies a completion-only
     * subject's prerequisite edge without ever being numerically compared.
     */
    public function isCompletion(): bool
    {
        return $this === self::Complete;
    }

    public function countsTowardGpa(): bool
    {
        return $this->isNumeric();
    }

    /**
     * The same four marks `ClassifyEnrollmentStanding` treats as neither
     * passing (`isPassing()`) nor completion (`isCompletion()`) when it
     * derives a student's term-scoped Regular/Irregular standing: a
     * required subject carrying one of these isn't actually done, whether
     * that shows up as a still-open standard-block subject or an open
     * backlog subject that needs adding.
     */
    public function blocksRegularStanding(): bool
    {
        return match ($this) {
            self::Failed, self::NotComplete, self::Incomplete, self::Dropped, self::Withdrawn => true,
            default => false,
        };
    }

    /**
     * @return list<self>
     */
    public static function numericCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $mark): bool => $mark->isNumeric()));
    }

    /**
     * @return list<self>
     */
    public static function completionCases(): array
    {
        return [self::Complete, self::NotComplete, self::Incomplete, self::Dropped, self::Withdrawn];
    }

    /**
     * @return list<self>
     */
    public static function completionOnlyCases(): array
    {
        return [self::Complete, self::Incomplete];
    }
}
