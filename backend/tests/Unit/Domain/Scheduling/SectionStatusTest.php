<?php

namespace Tests\Unit\Domain\Scheduling;

use App\Domain\Scheduling\SectionStatus;
use PHPUnit\Framework\TestCase;

final class SectionStatusTest extends TestCase
{
    public function test_status_values_are_the_four_provisional_cases(): void
    {
        $expected = ['planned', 'published', 'closed', 'cancelled'];
        $actual = array_column(SectionStatus::cases(), 'value');

        // Approved values must never disappear or be renamed; new slices may add more.
        self::assertSame([], array_values(array_diff($expected, $actual)));
        self::assertSame(array_values(array_unique($actual)), $actual);
    }

    public function test_labels_are_stable_and_human_readable(): void
    {
        self::assertSame('Planned', SectionStatus::Planned->label());
        self::assertSame('Published', SectionStatus::Published->label());
        self::assertSame('Closed', SectionStatus::Closed->label());
        self::assertSame('Cancelled', SectionStatus::Cancelled->label());
    }

    public function test_only_published_accepts_enrollment(): void
    {
        self::assertFalse(SectionStatus::Planned->acceptsEnrollment());
        self::assertTrue(SectionStatus::Published->acceptsEnrollment());
        self::assertFalse(SectionStatus::Closed->acceptsEnrollment());
        self::assertFalse(SectionStatus::Cancelled->acceptsEnrollment());
    }

    public function test_only_published_and_closed_are_visible_to_learners(): void
    {
        self::assertFalse(SectionStatus::Planned->isVisibleToLearners());
        self::assertTrue(SectionStatus::Published->isVisibleToLearners());
        self::assertTrue(SectionStatus::Closed->isVisibleToLearners());
        self::assertFalse(SectionStatus::Cancelled->isVisibleToLearners());
    }
}
