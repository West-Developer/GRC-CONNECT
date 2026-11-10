<?php

namespace Tests\Unit\Domain\Billing;

use App\Domain\Billing\AssessmentItemCategory;
use App\Domain\Billing\ScholarshipBase;
use App\Domain\Billing\ScholarshipTier;
use App\Models\AssessmentItem;
use PHPUnit\Framework\TestCase;

final class ScholarshipTierTest extends TestCase
{
    public function test_there_are_exactly_three_tiers(): void
    {
        self::assertSame([100, 40, 20], array_map(fn (ScholarshipTier $tier): int => $tier->value, ScholarshipTier::cases()));
    }

    public function test_each_tier_takes_its_share_of_the_base(): void
    {
        self::assertSame('1050.00', ScholarshipTier::Full->discountFor('1050.00'));
        self::assertSame('420.00', ScholarshipTier::Forty->discountFor('1050.00'));
        self::assertSame('210.00', ScholarshipTier::Twenty->discountFor('1050.00'));
        self::assertSame('0.00', ScholarshipTier::Forty->discountFor('0.00'));
    }

    public function test_the_discount_rounds_half_up_to_the_cent(): void
    {
        // 20% of 333.33 is 66.666, which rounds up to 66.67 like every other assessment amount.
        self::assertSame('66.67', ScholarshipTier::Twenty->discountFor('333.33'));
        // 40% of 0.01 is 0.004, which rounds down.
        self::assertSame('0.00', ScholarshipTier::Forty->discountFor('0.01'));
        // 40% of 0.0125 boundary: 0.005 rounds up to 0.01.
        self::assertSame('0.01', ScholarshipTier::Forty->discountFor('0.0125'));
    }

    public function test_the_line_is_labelled_and_stored_so_it_can_be_recomputed(): void
    {
        self::assertSame('Scholarship discount (40%)', ScholarshipTier::Forty->lineLabel());
        self::assertSame('100.0', ScholarshipTier::Full->storedQuantity());
        self::assertSame(ScholarshipTier::Forty, ScholarshipTier::fromStoredQuantity('40.0'));
        self::assertSame(ScholarshipTier::Full, ScholarshipTier::fromStoredQuantity('100.0'));
        self::assertNull(ScholarshipTier::fromStoredQuantity('35.0'));
        self::assertNull(ScholarshipTier::fromStoredQuantity(null));
    }

    public function test_the_base_leaves_out_an_existing_scholarship_line(): void
    {
        $line = fn (AssessmentItemCategory $category, string $amount): AssessmentItem => new AssessmentItem([
            'category' => $category, 'label' => 'x', 'amount' => $amount,
        ]);

        self::assertSame('1050.00', ScholarshipBase::amount([
            $line(AssessmentItemCategory::Tuition, '750.00'),
            $line(AssessmentItemCategory::Miscellaneous, '300.00'),
            $line(AssessmentItemCategory::ScholarshipDiscount, '-420.00'),
        ]));
        self::assertSame('0.00', ScholarshipBase::amount([]));
    }

    public function test_the_base_skips_both_discount_lines(): void
    {
        $items = [
            new AssessmentItem(['category' => 'tuition', 'amount' => '750.00']),
            new AssessmentItem(['category' => 'miscellaneous', 'amount' => '300.00']),
            new AssessmentItem(['category' => 'scholarship_discount', 'amount' => '-420.00']),
            new AssessmentItem(['category' => 'full_payment_discount', 'amount' => '-105.00']),
        ];

        self::assertSame('1050.00', ScholarshipBase::amount($items));
        self::assertTrue(AssessmentItemCategory::FullPaymentDiscount->isDiscount());
        self::assertTrue(AssessmentItemCategory::ScholarshipDiscount->isDiscount());
        self::assertFalse(AssessmentItemCategory::Tuition->isDiscount());
    }
}
