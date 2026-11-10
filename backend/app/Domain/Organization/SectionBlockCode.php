<?php

namespace App\Domain\Organization;

/**
 * Human-readable identifier for a generated regular block section.
 *
 * The code is distinct from a subject code: `IT101` means the first IT
 * first-year block, while every subject contained in that block keeps its own
 * catalog code. The ordinal is intentionally two digits to mirror GRC's
 * existing schedule sheets.
 */
final class SectionBlockCode
{
    public static function fromProgram(
        string $programCode,
        CollegeCode $college,
        int $yearLevel,
        int $blockOrdinal,
    ): string {
        $prefix = match ($college) {
            CollegeCode::Ccs => 'IT',
            CollegeCode::Coe => self::coePrefix($programCode),
            CollegeCode::Coa => 'ACC',
            CollegeCode::Cbae => self::cbaePrefix($programCode),
        };

        return sprintf('%s%d%02d', $prefix, $yearLevel, $blockOrdinal);
    }

    /**
     * COE has five distinct majors (Elementary Education, and Secondary
     * Education in Filipino/English/Social Studies/Values Education), each
     * with its own real block prefix in GRC's schedule sheets — unlike CCS/
     * COA, "College of Education" alone does not identify one program.
     */
    private static function coePrefix(string $programCode): string
    {
        $normalized = strtoupper(str_replace([' ', '_'], '', $programCode));

        return match (true) {
            str_contains($normalized, 'ELEM') || $normalized === 'BEED' => 'ELEM',
            str_contains($normalized, 'SOCSCI') => 'SOCSCI',
            str_contains($normalized, 'FIL') => 'FIL',
            str_contains($normalized, 'ENG') => 'ENG',
            str_contains($normalized, 'VAL') => 'VAL',
            str_contains($normalized, 'TCP') => 'TCP',
            default => 'EDUC',
        };
    }

    private static function cbaePrefix(string $programCode): string
    {
        $normalized = strtoupper(str_replace([' ', '_'], '', $programCode));

        return match (true) {
            str_contains($normalized, 'FINANCE') || str_contains($normalized, 'FM') => 'FM',
            str_contains($normalized, 'ENTREP') => 'EN',
            str_contains($normalized, 'MARKETING') || str_contains($normalized, 'MM') => 'MM',
            str_contains($normalized, 'HUMANRESOURCE') || str_contains($normalized, 'HRM') || str_contains($normalized, 'HR') => 'HR',
            default => 'CBAE',
        };
    }
}
