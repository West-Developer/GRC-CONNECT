<?php

namespace App\Domain\Academic;

/**
 * Identifies subjects graded Complete/Incomplete instead of numerically —
 * Leadership (LEAD 1–8) per user direction (2026-08-04). Pure and
 * config-driven (`config('enrollment.grading.completion_only_code_prefixes')`),
 * so amending which subjects are completion-only never requires a code
 * change.
 *
 * The seeded catalog spells the LEAD codes inconsistently —
 * `LEAD 1`, `LEAD2`, `LEAD 3`, `LEAD4`, `LEAD 5`, `LEAD6`, `LEAD 7`, `LEAD8`
 * all exist as real rows — so matching is done against a normalized code
 * (uppercased, non-alphanumerics stripped) with a `^PREFIX\d*$` pattern,
 * never `str_starts_with`, which would also match an unrelated code that
 * merely begins with the same letters (e.g. a hypothetical
 * `LEADERSHIP-ELECTIVE`).
 */
final class CompletionOnlySubjectRule
{
    public static function normalizeCode(string $code): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
    }

    /**
     * @param  list<string>  $prefixes
     */
    public static function matches(string $code, array $prefixes): bool
    {
        $normalized = self::normalizeCode($code);

        foreach ($prefixes as $prefix) {
            $normalizedPrefix = self::normalizeCode($prefix);

            if ($normalizedPrefix === '') {
                continue;
            }

            if (preg_match('/^'.preg_quote($normalizedPrefix, '/').'\d*$/', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $prefixes
     * @return list<GradeMark>
     */
    public static function allowedMarks(string $code, array $prefixes): array
    {
        if (self::matches($code, $prefixes)) {
            return GradeMark::completionOnlyCases();
        }

        return [
            ...GradeMark::numericCases(),
            GradeMark::Incomplete,
        ];
    }
}
