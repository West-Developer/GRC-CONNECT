<?php

namespace Tests\Unit\Models;

use App\Models\CurriculumSubject;
use PHPUnit\Framework\TestCase;

final class CurriculumSubjectTest extends TestCase
{
    public function test_year_level_and_is_required_use_their_canonical_casts(): void
    {
        $placement = new CurriculumSubject;
        $placement->forceFill([
            'curriculum_id' => 1,
            'subject_id' => 1,
            'year_level' => '2',
            'semester' => '1st',
            'is_required' => 1,
        ]);

        self::assertSame(2, $placement->year_level);
        self::assertTrue($placement->is_required);
    }
}
