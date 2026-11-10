<?php

namespace App\Domain\Organization;

/**
 * Normalizes the year and semester hints embedded in the supplied catalog
 * CSV. Section labels such as `IT 301`, `FM 102`, and `ELEM101` are the
 * authoritative year-level hints for the local development catalog.
 */
final class CatalogSectionMetadata
{
    public static function yearLevel(string $sections): int
    {
        preg_match_all('/\b([1-4])\d{2}\b/u', $sections, $matches);

        $levels = array_map('intval', $matches[1] ?? []);

        return $levels === [] ? 1 : min($levels);
    }

    public static function semester(string $offeredSemesters): string
    {
        $normalized = strtolower($offeredSemesters);
        $hasFirst = str_contains($normalized, '1st');
        $hasSecond = str_contains($normalized, '2nd');

        return match (true) {
            $hasFirst && $hasSecond => '1st|2nd',
            $hasSecond => '2nd',
            default => '1st',
        };
    }
}
