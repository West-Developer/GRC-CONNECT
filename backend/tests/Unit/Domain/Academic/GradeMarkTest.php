<?php

namespace Tests\Unit\Domain\Academic;

use App\Domain\Academic\GradeMark;
use PHPUnit\Framework\TestCase;

final class GradeMarkTest extends TestCase
{
    public function test_scale_values_match_grc_descending_scale(): void
    {
        self::assertSame(
            ['1.00', '1.25', '1.50', '1.75', '2.00', '2.25', '2.50', '2.75', '3.00', '5.00', 'C', 'NC', 'INC', 'DRP', 'W'],
            array_column(GradeMark::cases(), 'value'),
        );
    }

    public function test_labels_match_the_approved_scale(): void
    {
        self::assertSame('Excellent', GradeMark::Excellent->label());
        self::assertSame('High Distinction', GradeMark::HighDistinction->label());
        self::assertSame('with Distinction', GradeMark::WithDistinction->label());
        self::assertSame('Very Good', GradeMark::VeryGood->label());
        self::assertSame('Good', GradeMark::Good->label());
        self::assertSame('Very Satisfactory', GradeMark::VerySatisfactory->label());
        self::assertSame('Satisfactory', GradeMark::Satisfactory->label());
        self::assertSame('Fair', GradeMark::Fair->label());
        self::assertSame('Passed', GradeMark::Passed->label());
        self::assertSame('Failed', GradeMark::Failed->label());
        self::assertSame('Complete', GradeMark::Complete->label());
        self::assertSame('Not Complete', GradeMark::NotComplete->label());
        self::assertSame('Incomplete', GradeMark::Incomplete->label());
        self::assertSame('Dropped', GradeMark::Dropped->label());
        self::assertSame('Withdrawn', GradeMark::Withdrawn->label());
    }

    public function test_only_the_ten_numeric_marks_are_numeric(): void
    {
        foreach (GradeMark::numericCases() as $mark) {
            self::assertTrue($mark->isNumeric());
            self::assertSame((float) $mark->value, $mark->numericValue());
        }

        foreach (GradeMark::completionCases() as $mark) {
            self::assertFalse($mark->isNumeric());
            self::assertNull($mark->numericValue());
        }
    }

    public function test_numeric_cases_and_completion_cases_partition_all_cases(): void
    {
        $numeric = GradeMark::numericCases();
        $completion = GradeMark::completionCases();

        self::assertCount(10, $numeric);
        self::assertSame(['C', 'NC', 'INC', 'DRP', 'W'], array_column($completion, 'value'));
        self::assertCount(15, [...$numeric, ...$completion]);
    }

    public function test_is_passing_agrees_with_the_configured_3_00_threshold(): void
    {
        self::assertTrue(GradeMark::Excellent->isPassing());
        self::assertTrue(GradeMark::Passed->isPassing());
        self::assertFalse(GradeMark::Failed->isPassing());
        self::assertTrue(GradeMark::Complete->isPassing());
        self::assertFalse(GradeMark::NotComplete->isPassing());
        self::assertFalse(GradeMark::Incomplete->isPassing());
        self::assertFalse(GradeMark::Dropped->isPassing());

        // Guards against config/enrollment.php's grading.passing_grade
        // drifting away from the hard-coded 3.00 this enum uses (it stays
        // pure and config-free by design — see the class docblock). Required
        // directly, not via the config() helper: this is a bare PHPUnit test
        // with no Laravel application bootstrap.
        $config = require __DIR__.'/../../../../config/enrollment.php';
        self::assertSame(GradeMark::Passed->value, $config['grading']['passing_grade']);
    }

    public function test_only_5_00_is_failing(): void
    {
        self::assertTrue(GradeMark::Failed->isFailing());

        foreach (GradeMark::cases() as $mark) {
            if ($mark === GradeMark::Failed) {
                continue;
            }

            self::assertFalse($mark->isFailing());
        }
    }

    public function test_only_c_is_a_completion_mark(): void
    {
        self::assertTrue(GradeMark::Complete->isCompletion());

        foreach (GradeMark::cases() as $mark) {
            if ($mark === GradeMark::Complete) {
                continue;
            }

            self::assertFalse($mark->isCompletion());
        }
    }

    public function test_counts_toward_gpa_matches_is_numeric(): void
    {
        foreach (GradeMark::cases() as $mark) {
            self::assertSame($mark->isNumeric(), $mark->countsTowardGpa());
        }
    }

    public function test_blocks_regular_standing_matches_the_approved_irregular_triggers(): void
    {
        self::assertTrue(GradeMark::Failed->blocksRegularStanding());
        self::assertTrue(GradeMark::NotComplete->blocksRegularStanding());
        self::assertTrue(GradeMark::Incomplete->blocksRegularStanding());
        self::assertTrue(GradeMark::Dropped->blocksRegularStanding());

        self::assertFalse(GradeMark::Complete->blocksRegularStanding());
        self::assertFalse(GradeMark::Passed->blocksRegularStanding());
        self::assertFalse(GradeMark::Excellent->blocksRegularStanding());
    }
}
