<?php

namespace App\Domain\Academic;

/**
 * Compares a student's recorded grade against a required threshold
 * (FR-ENR-002), producing the explainable verdict FR-ENR-005 requires.
 * Pure and persistence-free — the same shape as PrerequisiteCycleDetector
 * and SectionConflictDetector. The caller resolves the student's grade and
 * the required threshold (a curriculum's per-edge
 * `subject_prerequisites.minimum_grade`, or the general
 * `config('enrollment.grading.passing_grade')` for a plain completed-subject
 * check) before calling `evaluate()`.
 *
 * The comparison direction and special-mark vocabulary are constructor
 * arguments, not calls to the `config()` helper, so this class stays
 * testable without touching Laravel's config bag and so its behavior when
 * the policy is unconfigured (`$comparison === null`) is exercised the same
 * way in every caller.
 */
final readonly class PrerequisiteEvaluator
{
    /**
     * @param  ?string  $comparison  'lower_is_better', 'higher_is_better', or null (unconfigured).
     * @param  list<string>  $specialMarks  Grade values that are never a pass, e.g. INC, NC.
     */
    public function __construct(
        private ?string $comparison,
        private array $specialMarks,
    ) {}

    public function evaluate(?string $studentGrade, string $requiredGrade): PrerequisiteVerdict
    {
        if ($studentGrade === null || trim($studentGrade) === '') {
            return PrerequisiteVerdict::notSatisfied('The prerequisite subject has not yet been completed.');
        }

        if (in_array(strtoupper(trim($studentGrade)), $this->specialMarks, true)) {
            return PrerequisiteVerdict::notSatisfied(
                sprintf('The recorded grade "%s" is incomplete, not a passing mark.', $studentGrade),
            );
        }

        if ($this->comparison === null) {
            return PrerequisiteVerdict::needsVerification(
                'The official grading policy has not been confirmed yet, so this prerequisite cannot be verified automatically.',
            );
        }

        if (! is_numeric($studentGrade) || ! is_numeric($requiredGrade)) {
            return PrerequisiteVerdict::needsVerification(
                sprintf('The recorded grade "%s" is not a comparable numeric value.', $studentGrade),
            );
        }

        $satisfied = match ($this->comparison) {
            'lower_is_better' => (float) $studentGrade <= (float) $requiredGrade,
            'higher_is_better' => (float) $studentGrade >= (float) $requiredGrade,
            default => null,
        };

        if ($satisfied === null) {
            return PrerequisiteVerdict::needsVerification(
                sprintf('The configured grading comparison "%s" is not recognized.', $this->comparison),
            );
        }

        return $satisfied
            ? PrerequisiteVerdict::satisfied(
                sprintf('Recorded grade %s meets the required %s.', $studentGrade, $requiredGrade),
            )
            : PrerequisiteVerdict::notSatisfied(
                sprintf('Recorded grade %s does not meet the required %s.', $studentGrade, $requiredGrade),
            );
    }
}
