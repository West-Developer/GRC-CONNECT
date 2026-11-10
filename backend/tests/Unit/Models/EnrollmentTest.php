<?php

namespace Tests\Unit\Models;

use App\Domain\Enrollment\EnrollmentStatus;
use App\Models\Enrollment;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class EnrollmentTest extends TestCase
{
    public function test_status_attribute_uses_the_canonical_enum_cast(): void
    {
        $enrollment = new Enrollment;
        $enrollment->forceFill([
            'student_id' => 1,
            'academic_term_id' => 1,
            'status' => 'enrolled',
            'total_units' => '15',
        ]);

        self::assertSame(EnrollmentStatus::Enrolled, $enrollment->status);
        self::assertSame(15.0, $enrollment->total_units);
    }

    public function test_lifecycle_timestamps_are_cast_to_carbon_immutable(): void
    {
        $enrollment = new Enrollment;
        $enrollment->forceFill([
            'student_id' => 1,
            'academic_term_id' => 1,
            'status' => 'enrolled',
            'submitted_at' => '2026-08-01 08:00:00',
            'enrolled_at' => '2026-08-05 09:00:00',
        ]);

        self::assertInstanceOf(CarbonImmutable::class, $enrollment->submitted_at);
        self::assertInstanceOf(CarbonImmutable::class, $enrollment->enrolled_at);
    }
}
