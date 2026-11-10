<?php

namespace Tests\Unit\Models;

use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Models\EnrollmentSubject;
use PHPUnit\Framework\TestCase;

final class EnrollmentSubjectTest extends TestCase
{
    public function test_status_attribute_uses_the_canonical_enum_cast(): void
    {
        $enrollmentSubject = new EnrollmentSubject;
        $enrollmentSubject->forceFill([
            'enrollment_id' => 1,
            'section_id' => 1,
            'status' => 'enrolled',
        ]);

        self::assertSame(EnrollmentSubjectStatus::Enrolled, $enrollmentSubject->status);
    }
}
