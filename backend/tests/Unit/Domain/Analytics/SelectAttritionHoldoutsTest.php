<?php

namespace Tests\Unit\Domain\Analytics;

use App\Domain\Analytics\SelectAttritionHoldouts;
use Tests\TestCase;

final class SelectAttritionHoldoutsTest extends TestCase
{
    public function test_it_selects_a_stable_rounded_five_percent_per_program_and_year_cohort(): void
    {
        $students = [
            ...$this->students('BSIT', 1, 30, 100),
            ...$this->students('BSIT', 2, 60, 200),
        ];

        $selected = (new SelectAttritionHoldouts)->select($students, 8);

        $this->assertCount(5, $selected);
        $this->assertSame(
            $selected,
            (new SelectAttritionHoldouts)->select($students, 8),
        );
        $this->assertSame(2, count(array_filter($selected, fn (int $id): bool => $id < 200)));
        $this->assertSame(3, count(array_filter($selected, fn (int $id): bool => $id >= 200)));
    }

    public function test_it_never_replaces_students_who_already_started_the_comparison_term(): void
    {
        $students = $this->students('BSA', 1, 30, 100);

        $selected = (new SelectAttritionHoldouts)->select($students, 8, [100, 101]);

        $this->assertCount(2, $selected);
        $this->assertNotContains(100, $selected);
        $this->assertNotContains(101, $selected);
    }

    /** @return list<array{id: int, student_number: string, program_code: string, year_level: int}> */
    private function students(string $programCode, int $yearLevel, int $count, int $firstId): array
    {
        return array_map(
            fn (int $offset): array => [
                'id' => $firstId + $offset,
                'student_number' => sprintf('2026-06-%05d', $firstId + $offset),
                'program_code' => $programCode,
                'year_level' => $yearLevel,
            ],
            range(0, $count - 1),
        );
    }
}
