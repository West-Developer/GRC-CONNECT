<?php

namespace Tests\Unit\Models;

use App\Domain\Scheduling\SectionStatus;
use App\Models\Section;
use PHPUnit\Framework\TestCase;

final class SectionTest extends TestCase
{
    public function test_status_attribute_uses_the_canonical_enum_cast(): void
    {
        $section = new Section;
        $section->forceFill([
            'academic_term_id' => 1,
            'subject_id' => 1,
            'section_code' => 'A',
            'capacity' => '40',
            'viability_threshold' => null,
            'enrolled_count' => '0',
            'status' => 'published',
        ]);

        self::assertSame(SectionStatus::Published, $section->status);
        self::assertSame(40, $section->capacity);
        self::assertSame(0, $section->enrolled_count);
    }

    public function test_remaining_seats_is_capacity_minus_enrolled_count(): void
    {
        $section = new Section;
        $section->forceFill(['capacity' => 40, 'enrolled_count' => 37]);

        self::assertSame(3, $section->remainingSeats());
    }

    public function test_remaining_seats_never_goes_negative_when_over_filled(): void
    {
        $section = new Section;
        $section->forceFill(['capacity' => 40, 'enrolled_count' => 45]);

        self::assertSame(0, $section->remainingSeats());
    }
}
