<?php

namespace Tests\Unit\Models;

use App\Domain\Curriculum\CurriculumStatus;
use App\Models\Curriculum;
use PHPUnit\Framework\TestCase;

final class CurriculumTest extends TestCase
{
    public function test_status_attribute_uses_the_canonical_enum_cast(): void
    {
        $curriculum = new Curriculum;
        $curriculum->forceFill([
            'program_id' => 1,
            'name' => 'BSCS 2026 Curriculum',
            'effective_school_year' => '2026-2027',
            'status' => 'draft',
        ]);

        self::assertSame(CurriculumStatus::Draft, $curriculum->status);
    }
}
