<?php

namespace Tests\Unit\Models;

use App\Domain\Billing\AssessmentItemCategory;
use App\Models\AssessmentItem;
use Tests\TestCase;

final class AssessmentItemTest extends TestCase
{
    public function test_category_is_cast_to_the_backed_enum(): void
    {
        $item = new AssessmentItem;
        $item->forceFill([
            'assessment_id' => 1,
            'category' => 'tuition',
            'label' => 'Tuition',
            'amount' => '4725.00',
        ]);

        self::assertSame(AssessmentItemCategory::Tuition, $item->category);
    }

    public function test_amount_and_quantity_deliberately_stay_raw_strings_not_floats(): void
    {
        $item = new AssessmentItem;
        $item->forceFill([
            'assessment_id' => 1,
            'category' => 'tuition',
            'label' => 'Tuition',
            'quantity' => '1.5',
            'unit_amount' => '450.00',
            'amount' => '675.00',
        ]);

        self::assertIsString($item->quantity);
        self::assertSame('1.5', $item->quantity);
        self::assertIsString($item->unit_amount);
        self::assertSame('450.00', $item->unit_amount);
        self::assertIsString($item->amount);
        self::assertSame('675.00', $item->amount);
    }

    public function test_miscellaneous_items_have_no_quantity_or_unit_amount(): void
    {
        $item = new AssessmentItem;
        $item->forceFill([
            'assessment_id' => 1,
            'category' => 'miscellaneous',
            'label' => 'Registration',
            'quantity' => null,
            'unit_amount' => null,
            'amount' => '350.00',
        ]);

        self::assertSame(AssessmentItemCategory::Miscellaneous, $item->category);
        self::assertNull($item->quantity);
        self::assertNull($item->unit_amount);
    }
}
