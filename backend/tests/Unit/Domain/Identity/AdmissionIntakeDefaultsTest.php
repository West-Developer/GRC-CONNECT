<?php

namespace Tests\Unit\Domain\Identity;

use App\Domain\Enrollment\EnrollmentCategory;
use App\Domain\Identity\AdmissionIntakeDefaults;
use App\Domain\Identity\StudentType;
use PHPUnit\Framework\TestCase;

final class AdmissionIntakeDefaultsTest extends TestCase
{
    public function test_year_level_one_defaults_to_regular(): void
    {
        self::assertSame(EnrollmentCategory::Regular, AdmissionIntakeDefaults::enrollmentCategoryFor(1));
    }

    public function test_year_levels_two_through_four_default_to_irregular(): void
    {
        foreach ([2, 3, 4] as $yearLevel) {
            self::assertSame(EnrollmentCategory::Irregular, AdmissionIntakeDefaults::enrollmentCategoryFor($yearLevel));
        }
    }

    public function test_year_level_one_defaults_to_freshman(): void
    {
        self::assertSame(StudentType::Freshman, AdmissionIntakeDefaults::studentTypeFor(1));
    }

    public function test_year_levels_two_through_four_default_to_transferee(): void
    {
        foreach ([2, 3, 4] as $yearLevel) {
            self::assertSame(StudentType::Transferee, AdmissionIntakeDefaults::studentTypeFor($yearLevel));
        }
    }
}
