<?php

namespace App\Domain\Curriculum;

/**
 * The single owner of `curriculum_subjects.semester`'s free-form grammar.
 * That column is not an enum — it can literally hold the composite string
 * `'1st|2nd'` (written by `CatalogSectionMetadata::semester()` for a subject
 * offered either semester) alongside plain `'1st'` / `'2nd'` values. Every
 * reader of that column (prospectus, classifier) must bucket it identically
 * or the two can silently disagree about which semester a subject belongs
 * to — so both call this class instead of parsing the string themselves.
 *
 * Parsing never throws: an unparseable or empty string buckets to `First`
 * rather than raising, since a bad curriculum row must never turn into a
 * 500 on a student's prospectus.
 */
final class SemesterCoverage
{
    /**
     * @return list<SemesterSlot>
     */
    public static function parse(string $raw): array
    {
        $normalized = strtolower($raw);
        $hasFirst = str_contains($normalized, '1st');
        $hasSecond = str_contains($normalized, '2nd');

        return match (true) {
            $hasFirst && $hasSecond => [SemesterSlot::First, SemesterSlot::Second],
            $hasSecond => [SemesterSlot::Second],
            default => [SemesterSlot::First],
        };
    }

    /**
     * The semester a placement is bucketed into on the prospectus and in
     * classification, before any actual grade history is consulted — the
     * earliest slot the raw string covers.
     */
    public static function primary(string $raw): SemesterSlot
    {
        return self::parse($raw)[0];
    }

    public static function coversBoth(string $raw): bool
    {
        return count(self::parse($raw)) === 2;
    }
}
