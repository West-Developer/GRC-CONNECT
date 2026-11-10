<?php

namespace Tests\Unit\Domain\Academic;

use App\Domain\Academic\GradeMark;
use App\Domain\Academic\GradePointAverage;
use PHPUnit\Framework\TestCase;

final class GradePointAverageTest extends TestCase
{
    public function test_empty_rows_produce_a_null_gpa_not_zero(): void
    {
        self::assertNull(GradePointAverage::compute([]));
        self::assertSame(0.0, GradePointAverage::gpaUnits([]));
    }

    public function test_rows_with_only_completion_marks_produce_a_null_gpa(): void
    {
        $rows = [
            ['mark' => GradeMark::Complete, 'units' => 1.5],
            ['mark' => GradeMark::NotComplete, 'units' => 1.5],
        ];

        self::assertNull(GradePointAverage::compute($rows));
        self::assertSame(0.0, GradePointAverage::gpaUnits($rows));
    }

    public function test_matches_the_real_grade_slip_totals_from_the_reference_document(): void
    {
        // Verbatim from the supplied GRC grade slip: 13 rows, TOTAL ACADEMIC
        // UNITS 22.50, GPA 1.68. LEAD 6 (1.50 units, NC) is the only
        // non-numeric row and must be excluded from the GPA denominator
        // while still counting toward academic units.
        $rows = [
            ['mark' => GradeMark::WithDistinction, 'units' => 2.00], // AMC 1.50
            ['mark' => GradeMark::WithDistinction, 'units' => 1.00], // AMCL 1.50
            ['mark' => GradeMark::Good, 'units' => 2.00],            // CAO 2.00
            ['mark' => GradeMark::Good, 'units' => 1.00],            // CAOL 2.00
            ['mark' => GradeMark::HighDistinction, 'units' => 2.00], // CAPS1 1.25
            ['mark' => GradeMark::HighDistinction, 'units' => 1.00], // CAPS1L 1.25
            ['mark' => GradeMark::WithDistinction, 'units' => 2.00], // IAS 1.50
            ['mark' => GradeMark::WithDistinction, 'units' => 1.00], // IASL 1.50
            ['mark' => GradeMark::NotComplete, 'units' => 1.50],     // LEAD 6 NC
            ['mark' => GradeMark::VeryGood, 'units' => 3.00],        // QMTHODS 1.75
            ['mark' => GradeMark::Good, 'units' => 3.00],            // RIZAL 2.00
            ['mark' => GradeMark::VeryGood, 'units' => 2.00],        // WST 1.75
            ['mark' => GradeMark::VeryGood, 'units' => 1.00],        // WSTL 1.75
        ];

        self::assertSame(22.50, GradePointAverage::academicUnits($rows));
        self::assertSame(21.00, GradePointAverage::gpaUnits($rows));
        self::assertSame('1.68', GradePointAverage::compute($rows));
    }

    public function test_a_failing_5_00_mark_is_included_in_the_gpa_not_excluded(): void
    {
        $rows = [
            ['mark' => GradeMark::Failed, 'units' => 3.0],
        ];

        self::assertSame('5.00', GradePointAverage::compute($rows));
        self::assertSame(3.0, GradePointAverage::gpaUnits($rows));
    }

    public function test_an_explicitly_excluded_numeric_subject_keeps_academic_units_but_not_gpa_units(): void
    {
        $rows = [
            ['mark' => GradeMark::Excellent, 'units' => 3.0, 'counts_toward_gpa' => true],
            ['mark' => GradeMark::Failed, 'units' => 2.0, 'counts_toward_gpa' => false],
        ];

        self::assertSame(5.0, GradePointAverage::academicUnits($rows));
        self::assertSame(3.0, GradePointAverage::gpaUnits($rows));
        self::assertSame('1.00', GradePointAverage::compute($rows));
    }

    public function test_null_mark_rows_are_excluded_from_both_totals(): void
    {
        $rows = [
            ['mark' => null, 'units' => 3.0],
            ['mark' => GradeMark::Passed, 'units' => 2.0],
        ];

        self::assertSame('3.00', GradePointAverage::compute($rows));
        self::assertSame(2.0, GradePointAverage::gpaUnits($rows));
        self::assertSame(5.0, GradePointAverage::academicUnits($rows));
    }

    public function test_rounding_at_the_xx5_boundary_rounds_away_from_zero(): void
    {
        // (1.25 + 1.00) / 2 = 1.125 -> rounds away from zero to 1.13, not 1.12.
        $rows = [
            ['mark' => GradeMark::HighDistinction, 'units' => 1.0],
            ['mark' => GradeMark::Excellent, 'units' => 1.0],
        ];

        self::assertSame('1.13', GradePointAverage::compute($rows));
    }

    public function test_unrounded_average_keeps_precision_for_honors_boundaries(): void
    {
        $rows = [
            ['mark' => GradeMark::WithDistinction, 'units' => 61.0],
            ['mark' => GradeMark::VeryGood, 'units' => 1.0],
        ];

        self::assertSame('1.50', GradePointAverage::compute($rows));
        self::assertGreaterThan(1.50, GradePointAverage::unrounded($rows));
    }
}
