<?php

namespace Tests\Unit\Domain\Organization;

use App\Domain\Organization\ProgramStatus;
use PHPUnit\Framework\TestCase;

final class ProgramStatusTest extends TestCase
{
    public function test_status_values_are_the_two_provisional_cases(): void
    {
        self::assertSame(['active', 'inactive'], array_column(ProgramStatus::cases(), 'value'));
    }

    public function test_labels_are_stable_and_human_readable(): void
    {
        self::assertSame('Active', ProgramStatus::Active->label());
        self::assertSame('Inactive', ProgramStatus::Inactive->label());
    }

    public function test_only_active_is_visible_to_learners(): void
    {
        self::assertTrue(ProgramStatus::Active->isVisibleToLearners());
        self::assertFalse(ProgramStatus::Inactive->isVisibleToLearners());
    }
}
