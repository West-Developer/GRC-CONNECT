<?php

namespace Tests\Unit\Domain\Enrollment;

use App\Domain\Enrollment\EnrollmentSubjectStatus;
use PHPUnit\Framework\TestCase;

final class EnrollmentSubjectStatusTest extends TestCase
{
    public function test_status_values_are_the_three_provisional_cases(): void
    {
        self::assertSame(
            ['selected', 'enrolled', 'dropped'],
            array_column(EnrollmentSubjectStatus::cases(), 'value'),
        );
    }

    public function test_labels_are_stable_and_human_readable(): void
    {
        self::assertSame('Selected', EnrollmentSubjectStatus::Selected->label());
        self::assertSame('Enrolled', EnrollmentSubjectStatus::Enrolled->label());
        self::assertSame('Dropped', EnrollmentSubjectStatus::Dropped->label());
    }

    public function test_only_dropped_does_not_occupy_a_seat(): void
    {
        self::assertTrue(EnrollmentSubjectStatus::Selected->occupiesSeat());
        self::assertTrue(EnrollmentSubjectStatus::Enrolled->occupiesSeat());
        self::assertFalse(EnrollmentSubjectStatus::Dropped->occupiesSeat());
    }
}
