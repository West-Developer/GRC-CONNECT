<?php

namespace App\Domain\Organization;

/**
 * Deterministic mapping of every student section across the four currently supported colleges.
 *
 * The map contains 107 sections serving 3210 students (30 per section, spanning the four
 * colleges: COE/CBAE/COA/CCS across years 1–4, plus special-case sections).
 *
 * **COE First-Year Normalization:** COE's EDUC first-year is a common block shared across
 * five majors (BEED, BSED-FIL, BSED-ENG, BSED-SOCSCI, BSED-VAL). The 210 first-year
 * students (7 sections of EDUC101–107) are distributed across these five programs in
 * proportion to their second-year section split: ELEM 3, FIL 1, ENG 2, SOCSCI 1, VAL 1
 * (8 sections total). Using the Largest Remainder Method:
 * - (3/8) × 7 = 2.625 → ELEM gets 2 sections (EDUC101–102)
 * - (2/8) × 7 = 1.75  → ENG gets 2 sections (EDUC104–105)
 * - (1/8) × 7 = 0.875 → FIL gets 1 section (EDUC103)
 * - (1/8) × 7 = 0.875 → SOCSCI gets 1 section (EDUC106)
 * - (1/8) × 7 = 0.875 → VAL gets 1 section (EDUC107)
 * Total: 2+2+1+1+1 = 7 sections, 210 students.
 *
 * **SOCSCI Normalization:** Section codes use SOCSCI (not SOC) to match
 * `SectionBlockCode::coePrefix()`'s expectation for the BSED-SOCSCI program.
 */
final class StudentRosterMap
{
    /**
     * @return list<array{college: CollegeCode, program_code: string, section_code: string, year_level: int, size: int}>
     */
    public static function sections(): array
    {
        return [
            // ========== COE Year 1 (EDUC): 7 sections, 210 students ==========
            // EDUC101–102: BEED (Elementary Education) — 2 sections
            ['college' => CollegeCode::Coe, 'program_code' => 'BEED', 'section_code' => 'EDUC101', 'year_level' => 1, 'size' => 30],
            ['college' => CollegeCode::Coe, 'program_code' => 'BEED', 'section_code' => 'EDUC102', 'year_level' => 1, 'size' => 30],

            // EDUC103: BSED-FIL (Filipino) — 1 section
            ['college' => CollegeCode::Coe, 'program_code' => 'BSED-FIL', 'section_code' => 'EDUC103', 'year_level' => 1, 'size' => 30],

            // EDUC104–105: BSED-ENG (English) — 2 sections
            ['college' => CollegeCode::Coe, 'program_code' => 'BSED-ENG', 'section_code' => 'EDUC104', 'year_level' => 1, 'size' => 30],
            ['college' => CollegeCode::Coe, 'program_code' => 'BSED-ENG', 'section_code' => 'EDUC105', 'year_level' => 1, 'size' => 30],

            // EDUC106: BSED-SOCSCI (Social Studies) — 1 section
            ['college' => CollegeCode::Coe, 'program_code' => 'BSED-SOCSCI', 'section_code' => 'EDUC106', 'year_level' => 1, 'size' => 30],

            // EDUC107: BSED-VAL (Values Education) — 1 section
            ['college' => CollegeCode::Coe, 'program_code' => 'BSED-VAL', 'section_code' => 'EDUC107', 'year_level' => 1, 'size' => 30],

            // ========== COE Year 2: 8 sections, 240 students ==========
            ['college' => CollegeCode::Coe, 'program_code' => 'BEED', 'section_code' => 'ELEM201', 'year_level' => 2, 'size' => 30],
            ['college' => CollegeCode::Coe, 'program_code' => 'BEED', 'section_code' => 'ELEM202', 'year_level' => 2, 'size' => 30],
            ['college' => CollegeCode::Coe, 'program_code' => 'BEED', 'section_code' => 'ELEM203', 'year_level' => 2, 'size' => 30],
            ['college' => CollegeCode::Coe, 'program_code' => 'BSED-FIL', 'section_code' => 'FIL201', 'year_level' => 2, 'size' => 30],
            ['college' => CollegeCode::Coe, 'program_code' => 'BSED-ENG', 'section_code' => 'ENG201', 'year_level' => 2, 'size' => 30],
            ['college' => CollegeCode::Coe, 'program_code' => 'BSED-ENG', 'section_code' => 'ENG202', 'year_level' => 2, 'size' => 30],
            ['college' => CollegeCode::Coe, 'program_code' => 'BSED-SOCSCI', 'section_code' => 'SOCSCI201', 'year_level' => 2, 'size' => 30],
            ['college' => CollegeCode::Coe, 'program_code' => 'BSED-VAL', 'section_code' => 'VAL201', 'year_level' => 2, 'size' => 30],

            // ========== COE Year 3: 7 sections, 210 students ==========
            ['college' => CollegeCode::Coe, 'program_code' => 'BEED', 'section_code' => 'ELEM301', 'year_level' => 3, 'size' => 30],
            ['college' => CollegeCode::Coe, 'program_code' => 'BEED', 'section_code' => 'ELEM302', 'year_level' => 3, 'size' => 30],
            ['college' => CollegeCode::Coe, 'program_code' => 'BSED-FIL', 'section_code' => 'FIL301', 'year_level' => 3, 'size' => 30],
            ['college' => CollegeCode::Coe, 'program_code' => 'BSED-FIL', 'section_code' => 'FIL302', 'year_level' => 3, 'size' => 30],
            ['college' => CollegeCode::Coe, 'program_code' => 'BSED-ENG', 'section_code' => 'ENG301', 'year_level' => 3, 'size' => 30],
            ['college' => CollegeCode::Coe, 'program_code' => 'BSED-SOCSCI', 'section_code' => 'SOCSCI301', 'year_level' => 3, 'size' => 30],
            ['college' => CollegeCode::Coe, 'program_code' => 'BSED-VAL', 'section_code' => 'VAL301', 'year_level' => 3, 'size' => 30],

            // ========== COE Year 4: 4 sections, 120 students ==========
            ['college' => CollegeCode::Coe, 'program_code' => 'BEED', 'section_code' => 'ELEM401', 'year_level' => 4, 'size' => 30],
            ['college' => CollegeCode::Coe, 'program_code' => 'BEED', 'section_code' => 'ELEM402', 'year_level' => 4, 'size' => 30],
            ['college' => CollegeCode::Coe, 'program_code' => 'BSED-ENG', 'section_code' => 'ENG401', 'year_level' => 4, 'size' => 30],
            ['college' => CollegeCode::Coe, 'program_code' => 'BSED-ENG', 'section_code' => 'ENG402', 'year_level' => 4, 'size' => 30],

            // ========== COE Other: 1 section, 30 students ==========
            ['college' => CollegeCode::Coe, 'program_code' => 'TCP', 'section_code' => 'TCP101', 'year_level' => 1, 'size' => 30],

            // ========== CBAE Year 1: 10 sections, 300 students ==========
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-FM', 'section_code' => 'FM101', 'year_level' => 1, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-FM', 'section_code' => 'FM102', 'year_level' => 1, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSENTREP', 'section_code' => 'EN101', 'year_level' => 1, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-MM', 'section_code' => 'MM101', 'year_level' => 1, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-MM', 'section_code' => 'MM102', 'year_level' => 1, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-MM', 'section_code' => 'MM103', 'year_level' => 1, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-MM', 'section_code' => 'MM104', 'year_level' => 1, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-HRM', 'section_code' => 'HR101', 'year_level' => 1, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-HRM', 'section_code' => 'HR102', 'year_level' => 1, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-HRM', 'section_code' => 'HR103', 'year_level' => 1, 'size' => 30],

            // ========== CBAE Year 2: 10 sections, 300 students ==========
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-FM', 'section_code' => 'FM201', 'year_level' => 2, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-FM', 'section_code' => 'FM202', 'year_level' => 2, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSENTREP', 'section_code' => 'EN201', 'year_level' => 2, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-MM', 'section_code' => 'MM201', 'year_level' => 2, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-MM', 'section_code' => 'MM202', 'year_level' => 2, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-MM', 'section_code' => 'MM203', 'year_level' => 2, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-MM', 'section_code' => 'MM204', 'year_level' => 2, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-HRM', 'section_code' => 'HR201', 'year_level' => 2, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-HRM', 'section_code' => 'HR202', 'year_level' => 2, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-HRM', 'section_code' => 'HR203', 'year_level' => 2, 'size' => 30],

            // ========== CBAE Year 3: 8 sections, 240 students ==========
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-FM', 'section_code' => 'FM301', 'year_level' => 3, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-FM', 'section_code' => 'FM302', 'year_level' => 3, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSENTREP', 'section_code' => 'EN301', 'year_level' => 3, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-MM', 'section_code' => 'MM301', 'year_level' => 3, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-MM', 'section_code' => 'MM302', 'year_level' => 3, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-MM', 'section_code' => 'MM303', 'year_level' => 3, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-HRM', 'section_code' => 'HR301', 'year_level' => 3, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-HRM', 'section_code' => 'HR302', 'year_level' => 3, 'size' => 30],

            // ========== CBAE Year 4: 8 sections, 240 students ==========
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSENTREP', 'section_code' => 'EN401', 'year_level' => 4, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-MM', 'section_code' => 'MM401', 'year_level' => 4, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-MM', 'section_code' => 'MM402', 'year_level' => 4, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-MM', 'section_code' => 'MM403', 'year_level' => 4, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-MM', 'section_code' => 'MM404', 'year_level' => 4, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-HRM', 'section_code' => 'HR401', 'year_level' => 4, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-HRM', 'section_code' => 'HR402', 'year_level' => 4, 'size' => 30],
            ['college' => CollegeCode::Cbae, 'program_code' => 'BSBA-HRM', 'section_code' => 'HR403', 'year_level' => 4, 'size' => 30],

            // ========== COA Year 1: 4 sections, 120 students ==========
            ['college' => CollegeCode::Coa, 'program_code' => 'BSA', 'section_code' => 'ACC101', 'year_level' => 1, 'size' => 30],
            ['college' => CollegeCode::Coa, 'program_code' => 'BSA', 'section_code' => 'ACC102', 'year_level' => 1, 'size' => 30],
            ['college' => CollegeCode::Coa, 'program_code' => 'BSA', 'section_code' => 'ACC103', 'year_level' => 1, 'size' => 30],
            ['college' => CollegeCode::Coa, 'program_code' => 'BSA', 'section_code' => 'ACC104', 'year_level' => 1, 'size' => 30],

            // ========== COA Year 2: 3 sections, 90 students ==========
            ['college' => CollegeCode::Coa, 'program_code' => 'BSA', 'section_code' => 'ACC201', 'year_level' => 2, 'size' => 30],
            ['college' => CollegeCode::Coa, 'program_code' => 'BSA', 'section_code' => 'ACC202', 'year_level' => 2, 'size' => 30],
            ['college' => CollegeCode::Coa, 'program_code' => 'BSA', 'section_code' => 'ACC203', 'year_level' => 2, 'size' => 30],

            // ========== COA Year 3: 3 sections, 90 students ==========
            ['college' => CollegeCode::Coa, 'program_code' => 'BSA', 'section_code' => 'ACC301', 'year_level' => 3, 'size' => 30],
            ['college' => CollegeCode::Coa, 'program_code' => 'BSA', 'section_code' => 'ACC302', 'year_level' => 3, 'size' => 30],
            ['college' => CollegeCode::Coa, 'program_code' => 'BSA', 'section_code' => 'ACC303', 'year_level' => 3, 'size' => 30],

            // ========== COA Year 4: 3 sections, 90 students ==========
            ['college' => CollegeCode::Coa, 'program_code' => 'BSA', 'section_code' => 'ACC401', 'year_level' => 4, 'size' => 30],
            ['college' => CollegeCode::Coa, 'program_code' => 'BSA', 'section_code' => 'ACC402', 'year_level' => 4, 'size' => 30],
            ['college' => CollegeCode::Coa, 'program_code' => 'BSA', 'section_code' => 'ACC403', 'year_level' => 4, 'size' => 30],

            // ========== CCS Year 1: 9 sections, 270 students ==========
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT101', 'year_level' => 1, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT102', 'year_level' => 1, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT103', 'year_level' => 1, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT104', 'year_level' => 1, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT105', 'year_level' => 1, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT106', 'year_level' => 1, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT107', 'year_level' => 1, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT108', 'year_level' => 1, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT109', 'year_level' => 1, 'size' => 30],

            // ========== CCS Year 2: 8 sections, 240 students ==========
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT201', 'year_level' => 2, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT202', 'year_level' => 2, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT203', 'year_level' => 2, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT204', 'year_level' => 2, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT205', 'year_level' => 2, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT206', 'year_level' => 2, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT207', 'year_level' => 2, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT208', 'year_level' => 2, 'size' => 30],

            // ========== CCS Year 3: 7 sections, 210 students ==========
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT301', 'year_level' => 3, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT302', 'year_level' => 3, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT303', 'year_level' => 3, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT304', 'year_level' => 3, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT305', 'year_level' => 3, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT306', 'year_level' => 3, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT307', 'year_level' => 3, 'size' => 30],

            // ========== CCS Year 4: 7 sections, 210 students ==========
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT401', 'year_level' => 4, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT402', 'year_level' => 4, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT403', 'year_level' => 4, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT404', 'year_level' => 4, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT405', 'year_level' => 4, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT406', 'year_level' => 4, 'size' => 30],
            ['college' => CollegeCode::Ccs, 'program_code' => 'BSIT', 'section_code' => 'IT407', 'year_level' => 4, 'size' => 30],
        ];
    }

    /**
     * Map year level to enrollment entry year.
     *
     * Year level is the student's current position in the program (1–4 for regular programs).
     * Entry year is the academic year in which the student enrolled. This method computes it
     * deterministically for scenarios where students progress linearly without repeats or gaps.
     *
     * @param  int  $yearLevel  The student's year level (1–4)
     * @return int The entry year (calendar year)
     */
    public static function entryYearFor(int $yearLevel): int
    {
        return match ($yearLevel) {
            4 => 2023,
            3 => 2024,
            2 => 2025,
            1 => 2026,
            default => throw new \InvalidArgumentException("Invalid year level: {$yearLevel}"),
        };
    }
}
