<?php

namespace Tests\Unit\Domain\Billing;

use App\Domain\Billing\AssessmentComputation;
use App\Domain\Billing\AssessmentItemCategory;
use PHPUnit\Framework\TestCase;

final class AssessmentComputationTest extends TestCase
{
    public function test_tuition_line_multiplies_units_by_the_per_unit_rate(): void
    {
        $result = AssessmentComputation::compute('10.5', '450.00', []);

        self::assertCount(1, $result->lines);
        $tuition = $result->lines[0];
        self::assertSame(AssessmentItemCategory::Tuition, $tuition->category);
        self::assertSame('10.5', $tuition->quantity);
        self::assertSame('450.00', $tuition->unitAmount);
        self::assertSame('4725.00', $tuition->amount);
        self::assertSame('4725.00', $result->totalAmount);
    }

    public function test_fractional_leadership_units_are_not_truncated(): void
    {
        // A LEAD subject alone is 1.5 units — exercises the exact fractional
        // value that an integer `quantity` column would silently truncate.
        $result = AssessmentComputation::compute('1.5', '350.55', []);

        self::assertSame('1.5', $result->lines[0]->quantity);
        // 1.5 * 350.55 = 525.825 -> half-up rounds to 525.83, not truncated to 525.82.
        self::assertSame('525.83', $result->lines[0]->amount);
        self::assertSame('525.83', $result->totalAmount);
    }

    public function test_miscellaneous_fees_are_flat_lines_with_no_quantity_or_rate(): void
    {
        $result = AssessmentComputation::compute('3.0', '450.00', [
            ['label' => 'Registration', 'amount' => '350.00'],
            ['label' => 'Library', 'amount' => '200.00'],
        ]);

        self::assertCount(3, $result->lines);

        $registration = $result->lines[1];
        self::assertSame(AssessmentItemCategory::Miscellaneous, $registration->category);
        self::assertSame('Registration', $registration->label);
        self::assertNull($registration->quantity);
        self::assertNull($registration->unitAmount);
        self::assertSame('350.00', $registration->amount);

        // 3.0 * 450.00 = 1350.00, + 350.00 + 200.00 = 1900.00
        self::assertSame('1900.00', $result->totalAmount);
    }

    public function test_total_amount_is_always_exactly_the_sum_of_its_own_lines(): void
    {
        $result = AssessmentComputation::compute('10.5', '450.00', [
            ['label' => 'Registration', 'amount' => '350.00'],
            ['label' => 'Library', 'amount' => '200.00'],
            ['label' => 'Laboratory', 'amount' => '500.00'],
        ]);

        $sum = '0.00';
        foreach ($result->lines as $line) {
            $sum = bcadd($sum, $line->amount, 2);
        }

        self::assertSame($sum, $result->totalAmount);
        self::assertSame('5775.00', $result->totalAmount);
    }

    public function test_no_miscellaneous_fees_still_produces_a_valid_assessment(): void
    {
        $result = AssessmentComputation::compute('3.0', '450.00', []);

        self::assertCount(1, $result->lines);
        self::assertSame('1350.00', $result->totalAmount);
    }

    public function test_zero_units_produces_a_zero_tuition_line(): void
    {
        $result = AssessmentComputation::compute('0', '450.00', []);

        self::assertSame('0.00', $result->lines[0]->amount);
        self::assertSame('0.00', $result->totalAmount);
    }
}
