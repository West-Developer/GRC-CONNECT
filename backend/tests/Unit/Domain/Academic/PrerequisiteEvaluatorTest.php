<?php

namespace Tests\Unit\Domain\Academic;

use App\Domain\Academic\PrerequisiteEvaluator;
use App\Domain\Academic\PrerequisiteVerdictStatus;
use PHPUnit\Framework\TestCase;

final class PrerequisiteEvaluatorTest extends TestCase
{
    private function lowerIsBetterEvaluator(): PrerequisiteEvaluator
    {
        return new PrerequisiteEvaluator('lower_is_better', ['INC', 'NC']);
    }

    public function test_a_missing_grade_is_not_satisfied(): void
    {
        $verdict = $this->lowerIsBetterEvaluator()->evaluate(null, '3.00');

        self::assertSame(PrerequisiteVerdictStatus::NotSatisfied, $verdict->status);
        self::assertFalse($verdict->isSatisfied());
    }

    public function test_a_blank_grade_is_not_satisfied(): void
    {
        $verdict = $this->lowerIsBetterEvaluator()->evaluate('  ', '3.00');

        self::assertSame(PrerequisiteVerdictStatus::NotSatisfied, $verdict->status);
    }

    public function test_a_special_mark_is_not_satisfied_regardless_of_case(): void
    {
        $incomplete = $this->lowerIsBetterEvaluator()->evaluate('inc', '3.00');
        $notComplete = $this->lowerIsBetterEvaluator()->evaluate('NC', '3.00');

        self::assertSame(PrerequisiteVerdictStatus::NotSatisfied, $incomplete->status);
        self::assertSame(PrerequisiteVerdictStatus::NotSatisfied, $notComplete->status);
        self::assertStringContainsString('incomplete', $incomplete->reason);
    }

    public function test_a_grade_meeting_the_threshold_is_satisfied_under_lower_is_better(): void
    {
        $exact = $this->lowerIsBetterEvaluator()->evaluate('3.00', '3.00');
        $better = $this->lowerIsBetterEvaluator()->evaluate('1.75', '3.00');

        self::assertTrue($exact->isSatisfied());
        self::assertTrue($better->isSatisfied());
    }

    public function test_a_grade_failing_the_threshold_is_not_satisfied_under_lower_is_better(): void
    {
        $verdict = $this->lowerIsBetterEvaluator()->evaluate('5.00', '3.00');

        self::assertFalse($verdict->isSatisfied());
        self::assertSame(PrerequisiteVerdictStatus::NotSatisfied, $verdict->status);
    }

    public function test_higher_is_better_inverts_the_comparison(): void
    {
        $evaluator = new PrerequisiteEvaluator('higher_is_better', []);

        self::assertTrue($evaluator->evaluate('90', '75')->isSatisfied());
        self::assertFalse($evaluator->evaluate('60', '75')->isSatisfied());
    }

    public function test_an_unconfigured_comparison_needs_verification_rather_than_guessing(): void
    {
        $evaluator = new PrerequisiteEvaluator(null, ['INC', 'NC']);

        $verdict = $evaluator->evaluate('3.00', '3.00');

        self::assertSame(PrerequisiteVerdictStatus::NeedsVerification, $verdict->status);
        self::assertFalse($verdict->isSatisfied());
    }

    public function test_a_non_numeric_grade_needs_verification_rather_than_a_silent_pass_or_fail(): void
    {
        $verdict = $this->lowerIsBetterEvaluator()->evaluate('Pending Review', '3.00');

        self::assertSame(PrerequisiteVerdictStatus::NeedsVerification, $verdict->status);
    }

    public function test_an_unrecognized_comparison_direction_needs_verification(): void
    {
        $evaluator = new PrerequisiteEvaluator('sideways', []);

        $verdict = $evaluator->evaluate('3.00', '3.00');

        self::assertSame(PrerequisiteVerdictStatus::NeedsVerification, $verdict->status);
    }
}
