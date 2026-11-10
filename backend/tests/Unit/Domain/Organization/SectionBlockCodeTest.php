<?php

namespace Tests\Unit\Domain\Organization;

use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\SectionBlockCode;
use PHPUnit\Framework\TestCase;

final class SectionBlockCodeTest extends TestCase
{
    public function test_it_formats_ccs_and_coa_blocks_by_year_and_ordinal(): void
    {
        self::assertSame('IT101', SectionBlockCode::fromProgram('BSIT', CollegeCode::Ccs, 1, 1));
        self::assertSame('IT203', SectionBlockCode::fromProgram('BSCS', CollegeCode::Ccs, 2, 3));
        self::assertSame('ACC401', SectionBlockCode::fromProgram('BSA', CollegeCode::Coa, 4, 1));
    }

    /**
     * COE has five distinct majors, each with its own real block prefix
     * (ELEM/FIL/ENG/SOCSCI/VAL), not one shared "EDUC" prefix per college —
     * "EDUC" only ever labels COE's common first-year gen-ed block, which is
     * fanned out to every major's own curriculum, never used as a section
     * block prefix itself.
     */
    public function test_it_uses_the_coe_major_prefix(): void
    {
        self::assertSame('ELEM102', SectionBlockCode::fromProgram('BEED', CollegeCode::Coe, 1, 2));
        self::assertSame('FIL201', SectionBlockCode::fromProgram('BSED-FIL', CollegeCode::Coe, 2, 1));
        self::assertSame('ENG301', SectionBlockCode::fromProgram('BSED-ENG', CollegeCode::Coe, 3, 1));
        self::assertSame('SOCSCI201', SectionBlockCode::fromProgram('BSED-SOCSCI', CollegeCode::Coe, 2, 1));
        self::assertSame('VAL201', SectionBlockCode::fromProgram('BSED-VAL', CollegeCode::Coe, 2, 1));
        self::assertSame('TCP101', SectionBlockCode::fromProgram('TCP', CollegeCode::Coe, 1, 1));
    }

    public function test_it_uses_the_cbae_major_prefix(): void
    {
        self::assertSame('FM101', SectionBlockCode::fromProgram('BSBA-FM', CollegeCode::Cbae, 1, 1));
        self::assertSame('EN102', SectionBlockCode::fromProgram('BSENTREP', CollegeCode::Cbae, 1, 2));
        self::assertSame('MM201', SectionBlockCode::fromProgram('BSBA-MM', CollegeCode::Cbae, 2, 1));
        self::assertSame('HR301', SectionBlockCode::fromProgram('BSBA-HRM', CollegeCode::Cbae, 3, 1));
    }
}
