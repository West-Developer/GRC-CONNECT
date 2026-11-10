<?php

namespace App\Domain\Academic;

/** The approved non-GWA subject families, normalized across catalog variants. */
final class SubjectGwaExclusionRule
{
    /** @var list<string> */
    private const EXCLUDED_PREFIXES = ['NSTP', 'PATHFIT', 'PE'];

    public static function countsTowardGwa(string $code): bool
    {
        $normalized = CompletionOnlySubjectRule::normalizeCode($code);

        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return false;
            }
        }

        return true;
    }
}
