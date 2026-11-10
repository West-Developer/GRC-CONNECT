<?php

namespace Tests\Unit\Models;

use App\Domain\Organization\ProgramStatus;
use App\Models\Program;
use PHPUnit\Framework\TestCase;

final class ProgramTest extends TestCase
{
    public function test_status_attribute_uses_the_canonical_enum_cast(): void
    {
        $program = new Program;
        $program->forceFill([
            'code' => 'BSCS',
            'name' => 'BS Computer Science',
            'status' => 'active',
        ]);

        self::assertSame(ProgramStatus::Active, $program->status);
    }
}
