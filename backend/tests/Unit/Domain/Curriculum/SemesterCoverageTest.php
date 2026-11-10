<?php

namespace Tests\Unit\Domain\Curriculum;

use App\Domain\Curriculum\SemesterCoverage;
use App\Domain\Curriculum\SemesterSlot;
use PHPUnit\Framework\TestCase;

final class SemesterCoverageTest extends TestCase
{
    public function test_a_composite_1st_2nd_string_covers_both_slots(): void
    {
        self::assertSame([SemesterSlot::First, SemesterSlot::Second], SemesterCoverage::parse('1st|2nd'));
        self::assertTrue(SemesterCoverage::coversBoth('1st|2nd'));
        self::assertSame(SemesterSlot::First, SemesterCoverage::primary('1st|2nd'));
    }

    public function test_a_plain_1st_string_covers_only_first(): void
    {
        self::assertSame([SemesterSlot::First], SemesterCoverage::parse('1st'));
        self::assertFalse(SemesterCoverage::coversBoth('1st'));
        self::assertSame(SemesterSlot::First, SemesterCoverage::primary('1st'));
    }

    public function test_a_plain_2nd_string_covers_only_second(): void
    {
        self::assertSame([SemesterSlot::Second], SemesterCoverage::parse('2nd'));
        self::assertFalse(SemesterCoverage::coversBoth('2nd'));
        self::assertSame(SemesterSlot::Second, SemesterCoverage::primary('2nd'));
    }

    public function test_an_empty_string_buckets_to_first_rather_than_throwing(): void
    {
        self::assertSame([SemesterSlot::First], SemesterCoverage::parse(''));
        self::assertSame(SemesterSlot::First, SemesterCoverage::primary(''));
    }

    public function test_unparseable_garbage_buckets_to_first_rather_than_throwing(): void
    {
        self::assertSame([SemesterSlot::First], SemesterCoverage::parse('Summer'));
        self::assertSame(SemesterSlot::First, SemesterCoverage::primary('Summer'));
    }

    public function test_matching_is_case_insensitive(): void
    {
        self::assertSame([SemesterSlot::Second], SemesterCoverage::parse('2ND'));
        self::assertTrue(SemesterCoverage::coversBoth('1ST|2ND'));
    }
}
