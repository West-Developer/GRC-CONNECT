<?php

namespace Tests\Unit\Domain\Enrollment;

use App\Domain\Enrollment\EnrollmentChangeRequestType;
use PHPUnit\Framework\TestCase;

final class EnrollmentChangeRequestTypeTest extends TestCase
{
    public function test_type_values_are_the_three_cases(): void
    {
        self::assertSame(
            ['add', 'drop', 'change_section'],
            array_column(EnrollmentChangeRequestType::cases(), 'value'),
        );
    }

    public function test_labels_are_stable_and_human_readable(): void
    {
        self::assertSame('Add subject', EnrollmentChangeRequestType::Add->label());
        self::assertSame('Drop subject', EnrollmentChangeRequestType::Drop->label());
        self::assertSame('Change subject', EnrollmentChangeRequestType::ChangeSection->label());
    }

    public function test_only_add_omits_a_from_section(): void
    {
        self::assertFalse(EnrollmentChangeRequestType::Add->requiresFromSection());
        self::assertTrue(EnrollmentChangeRequestType::Drop->requiresFromSection());
        self::assertTrue(EnrollmentChangeRequestType::ChangeSection->requiresFromSection());
    }

    public function test_only_drop_omits_a_to_section(): void
    {
        self::assertTrue(EnrollmentChangeRequestType::Add->requiresToSection());
        self::assertFalse(EnrollmentChangeRequestType::Drop->requiresToSection());
        self::assertTrue(EnrollmentChangeRequestType::ChangeSection->requiresToSection());
    }
}
