<?php

namespace Tests\Unit\Domain\Organization;

use App\Domain\Organization\StudentRosterMap;
use Tests\TestCase;

class StudentRosterMapTest extends TestCase
{
    public function test_the_roster_map_matches_the_published_section_counts(): void
    {
        $sections = StudentRosterMap::sections();

        $this->assertCount(107, $sections);
        $this->assertSame(3210, array_sum(array_column($sections, 'size')));

        $byCollege = collect($sections)->groupBy(fn ($s) => $s['college']->value)->map->count();
        $this->assertSame(27, $byCollege['coe']);
        $this->assertSame(36, $byCollege['cbae']);
        $this->assertSame(13, $byCollege['coa']);
        $this->assertSame(31, $byCollege['ccs']);
    }

    public function test_coe_first_year_keeps_the_common_educ_block_code(): void
    {
        $educ = collect(StudentRosterMap::sections())->where('section_code', 'EDUC101')->sole();

        $this->assertSame(1, $educ['year_level']);
        $this->assertContains($educ['program_code'], ['BEED', 'BSED-FIL', 'BSED-ENG', 'BSED-SOCSCI', 'BSED-VAL']);
    }
}
