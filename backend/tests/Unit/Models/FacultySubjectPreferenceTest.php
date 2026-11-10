<?php

namespace Tests\Unit\Models;

use App\Models\FacultySubjectPreference;
use PHPUnit\Framework\TestCase;

final class FacultySubjectPreferenceTest extends TestCase
{
    public function test_rank_uses_the_canonical_integer_cast(): void
    {
        $preference = new FacultySubjectPreference;
        $preference->forceFill([
            'professor_id' => 1,
            'academic_term_id' => 1,
            'subject_id' => 1,
            'rank' => '2',
        ]);

        self::assertSame(2, $preference->rank);
    }
}
