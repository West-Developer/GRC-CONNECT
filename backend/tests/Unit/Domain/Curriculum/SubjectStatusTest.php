<?php

namespace Tests\Unit\Domain\Curriculum;

use App\Domain\Curriculum\SubjectStatus;
use PHPUnit\Framework\TestCase;

final class SubjectStatusTest extends TestCase
{
    public function test_status_values_are_the_two_provisional_cases(): void
    {
        self::assertSame(['active', 'inactive'], array_column(SubjectStatus::cases(), 'value'));
    }

    public function test_labels_are_stable_and_human_readable(): void
    {
        self::assertSame('Active', SubjectStatus::Active->label());
        self::assertSame('Inactive', SubjectStatus::Inactive->label());
    }

    public function test_only_active_is_visible_to_learners(): void
    {
        self::assertTrue(SubjectStatus::Active->isVisibleToLearners());
        self::assertFalse(SubjectStatus::Inactive->isVisibleToLearners());
    }
}
