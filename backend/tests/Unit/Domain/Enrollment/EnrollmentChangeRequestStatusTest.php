<?php

namespace Tests\Unit\Domain\Enrollment;

use App\Domain\Enrollment\EnrollmentChangeRequestStatus;
use PHPUnit\Framework\TestCase;

final class EnrollmentChangeRequestStatusTest extends TestCase
{
    public function test_status_values_are_the_three_cases(): void
    {
        self::assertSame(
            ['pending', 'approved', 'rejected'],
            array_column(EnrollmentChangeRequestStatus::cases(), 'value'),
        );
    }

    public function test_labels_are_stable_and_human_readable(): void
    {
        self::assertSame('Pending', EnrollmentChangeRequestStatus::Pending->label());
        self::assertSame('Approved', EnrollmentChangeRequestStatus::Approved->label());
        self::assertSame('Rejected', EnrollmentChangeRequestStatus::Rejected->label());
    }
}
