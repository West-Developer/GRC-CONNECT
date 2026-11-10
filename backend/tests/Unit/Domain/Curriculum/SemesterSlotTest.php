<?php

namespace Tests\Unit\Domain\Curriculum;

use App\Domain\Curriculum\SemesterSlot;
use PHPUnit\Framework\TestCase;

final class SemesterSlotTest extends TestCase
{
    public function test_ordinal_increases_monotonically_across_the_four_year_plan(): void
    {
        self::assertSame(1, SemesterSlot::First->ordinal(1));
        self::assertSame(2, SemesterSlot::Second->ordinal(1));
        self::assertSame(3, SemesterSlot::First->ordinal(2));
        self::assertSame(4, SemesterSlot::Second->ordinal(2));
        self::assertSame(5, SemesterSlot::First->ordinal(3));
        self::assertSame(6, SemesterSlot::Second->ordinal(3));
        self::assertSame(7, SemesterSlot::First->ordinal(4));
        self::assertSame(8, SemesterSlot::Second->ordinal(4));
    }

    public function test_labels(): void
    {
        self::assertSame('1st Semester', SemesterSlot::First->label());
        self::assertSame('2nd Semester', SemesterSlot::Second->label());
    }
}
