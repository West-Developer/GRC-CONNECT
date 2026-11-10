<?php

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\AcademicStanding;
use PHPUnit\Framework\TestCase;

final class AcademicStandingTest extends TestCase
{
    public function test_status_values_are_the_three_provisional_cases(): void
    {
        self::assertSame(
            ['good', 'probation', 'warning'],
            array_column(AcademicStanding::cases(), 'value'),
        );
    }

    public function test_labels_are_stable_and_human_readable(): void
    {
        self::assertSame('Good Standing', AcademicStanding::Good->label());
        self::assertSame('Probation', AcademicStanding::Probation->label());
        self::assertSame('Warning', AcademicStanding::Warning->label());
    }
}
