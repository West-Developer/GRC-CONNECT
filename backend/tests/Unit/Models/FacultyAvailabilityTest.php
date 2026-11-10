<?php

namespace Tests\Unit\Models;

use App\Models\FacultyAvailability;
use PHPUnit\Framework\TestCase;

final class FacultyAvailabilityTest extends TestCase
{
    public function test_day_of_week_uses_the_canonical_integer_cast(): void
    {
        $availability = new FacultyAvailability;
        $availability->forceFill([
            'professor_id' => 1,
            'academic_term_id' => 1,
            'day_of_week' => '1',
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '09:00:00',
        ]);

        self::assertSame(1, $availability->day_of_week);
    }

    public function test_the_table_name_is_pinned_explicitly(): void
    {
        self::assertSame('faculty_availabilities', (new FacultyAvailability)->getTable());
    }
}
