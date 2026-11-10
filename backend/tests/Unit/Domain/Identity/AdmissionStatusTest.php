<?php

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\AdmissionStatus;
use PHPUnit\Framework\TestCase;

final class AdmissionStatusTest extends TestCase
{
    public function test_status_values_are_the_five_provisional_cases(): void
    {
        self::assertSame(
            ['pending', 'admitted', 'enrolled', 'graduated', 'withdrawn'],
            array_column(AdmissionStatus::cases(), 'value'),
        );
    }

    public function test_labels_are_stable_and_human_readable(): void
    {
        self::assertSame('Pending', AdmissionStatus::Pending->label());
        self::assertSame('Admitted', AdmissionStatus::Admitted->label());
        self::assertSame('Enrolled', AdmissionStatus::Enrolled->label());
        self::assertSame('Graduated', AdmissionStatus::Graduated->label());
        self::assertSame('Withdrawn', AdmissionStatus::Withdrawn->label());
    }
}
