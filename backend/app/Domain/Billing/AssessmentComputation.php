<?php

namespace App\Domain\Billing;

/**
 * Computes what a student owes for one enrollment: tuition (units × the
 * per-unit rate) plus every flat miscellaneous fee. Pure — no DB, no
 * Eloquent, no `config()` call in here; the caller (`App\Actions\Billing\
 * AssessEnrollment`) reads config and passes primitives in, the same
 * boundary `App\Domain\Academic\PrerequisiteEvaluator` draws against its
 * caller.
 *
 * Every monetary value in and out is a decimal **string**, never a float —
 * `App\Models\Payment::$amount` is deliberately uncast for the identical
 * reason (binary floating point cannot represent money exactly). All
 * arithmetic goes through `bcmath`.
 *
 * Rounding is half-up at two decimal places — provisional, see
 * `config/fees.php`'s own docblock. `bcmul()`/`bcadd()` TRUNCATE rather
 * than round, so `multiplyRounded()` computes at 4-decimal precision,
 * adds half a centavo, then truncates to 2 — the standard bcmath half-up
 * idiom.
 */
final class AssessmentComputation
{
    /**
     * @param  numeric-string  $totalUnits
     * @param  numeric-string  $tuitionPerUnit
     * @param  list<array{label: string, amount: numeric-string}>  $miscellaneous
     */
    public static function compute(
        string $totalUnits,
        string $tuitionPerUnit,
        array $miscellaneous,
    ): ComputedAssessment {
        $lines = [];
        $total = '0.00';

        $tuitionAmount = self::multiplyRounded($totalUnits, $tuitionPerUnit);
        $lines[] = new AssessmentLine(
            category: AssessmentItemCategory::Tuition,
            label: 'Tuition',
            quantity: $totalUnits,
            unitAmount: $tuitionPerUnit,
            amount: $tuitionAmount,
        );
        $total = bcadd($total, $tuitionAmount, 2);

        foreach ($miscellaneous as $fee) {
            $amount = bcadd($fee['amount'], '0', 2);
            $lines[] = new AssessmentLine(
                category: AssessmentItemCategory::Miscellaneous,
                label: $fee['label'],
                quantity: null,
                unitAmount: null,
                amount: $amount,
            );
            $total = bcadd($total, $amount, 2);
        }

        return new ComputedAssessment($lines, $total);
    }

    /**
     * @param  numeric-string  $a
     * @param  numeric-string  $b
     * @return numeric-string
     */
    private static function multiplyRounded(string $a, string $b): string
    {
        return bcadd(bcmul($a, $b, 4), '0.005', 2);
    }
}
