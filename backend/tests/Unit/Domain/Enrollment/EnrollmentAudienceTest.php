<?php

namespace Tests\Unit\Domain\Enrollment;

use App\Domain\Enrollment\EnrollmentAudience;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnrollmentAudienceTest extends TestCase
{
    public function test_cases_are_the_approved_audiences_in_order(): void
    {
        self::assertSame(
            ['year_1', 'year_2', 'year_3', 'year_4', 'irregular', 'late_enrollee'],
            array_column(EnrollmentAudience::cases(), 'value'),
        );
    }

    #[DataProvider('yearLevelAudiences')]
    public function test_year_level_round_trips_through_from_year_level(int $yearLevel, EnrollmentAudience $expected): void
    {
        self::assertSame($expected, EnrollmentAudience::fromYearLevel($yearLevel));
        self::assertSame($yearLevel, $expected->yearLevel());
    }

    /** @return array<string, array{int, EnrollmentAudience}> */
    public static function yearLevelAudiences(): array
    {
        return [
            '1st year' => [1, EnrollmentAudience::Year1],
            '2nd year' => [2, EnrollmentAudience::Year2],
            '3rd year' => [3, EnrollmentAudience::Year3],
            '4th year' => [4, EnrollmentAudience::Year4],
        ];
    }

    public function test_irregular_and_late_enrollee_have_no_single_year_level(): void
    {
        self::assertNull(EnrollmentAudience::Irregular->yearLevel());
        self::assertNull(EnrollmentAudience::LateEnrollee->yearLevel());
    }

    public function test_an_unsupported_year_level_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EnrollmentAudience::fromYearLevel(5);
    }

    public function test_a_regular_student_resolves_to_their_year_level_audience(): void
    {
        self::assertSame(EnrollmentAudience::Year2, EnrollmentAudience::forStudent(null, 2));
        self::assertSame(EnrollmentAudience::Year3, EnrollmentAudience::forStudent('regular', 3));
    }

    public function test_an_irregular_student_resolves_to_the_irregular_audience_regardless_of_year_level(): void
    {
        self::assertSame(EnrollmentAudience::Irregular, EnrollmentAudience::forStudent('irregular', 2));
    }

    public function test_the_irregular_category_match_is_case_insensitive(): void
    {
        self::assertSame(EnrollmentAudience::Irregular, EnrollmentAudience::forStudent('IRREGULAR', 1));
        self::assertSame(EnrollmentAudience::Irregular, EnrollmentAudience::forStudent('Irregular', 4));
    }

    public function test_labels_are_ordinal_for_year_levels_and_named_for_irregular_and_late(): void
    {
        self::assertSame('1st Year', EnrollmentAudience::Year1->label());
        self::assertSame('2nd Year', EnrollmentAudience::Year2->label());
        self::assertSame('3rd Year', EnrollmentAudience::Year3->label());
        self::assertSame('4th Year', EnrollmentAudience::Year4->label());
        self::assertSame('Irregular Students', EnrollmentAudience::Irregular->label());
        self::assertSame('Late Enrollees', EnrollmentAudience::LateEnrollee->label());
    }
}
