<?php

namespace App\Domain\Organization;

/**
 * Produces stable, human-readable local/test faculty identities from the
 * workbook's inconsistent display names. It deliberately does not guess that
 * a surname-only row is the same person as a named row with that surname.
 */
final class WorkbookFacultyProfileName
{
    /** @var list<string> */
    private const FALLBACK_GIVEN_NAMES = [
        'Adrian', 'Bianca', 'Carlos', 'Diana', 'Elena', 'Felix', 'Grace',
        'Hector', 'Iris', 'Jonas', 'Kara', 'Lorenzo', 'Mara', 'Nicolas',
        'Olivia', 'Paolo', 'Quinn', 'Rafael', 'Sofia', 'Tomas', 'Uma',
        'Victor', 'Wendy', 'Xavier', 'Yvonne', 'Zara',
    ];

    /** @var array<string, string> */
    private const INITIAL_EXPANSIONS = [
        'A' => 'Adrian', 'B' => 'Bianca', 'C' => 'Carlos', 'D' => 'Diana',
        'E' => 'Elena', 'F' => 'Felix', 'G' => 'Grace', 'H' => 'Hector',
        'I' => 'Iris', 'J' => 'Jonas', 'K' => 'Kara', 'L' => 'Lorenzo',
        'M' => 'Mara', 'N' => 'Nicolas', 'O' => 'Olivia', 'P' => 'Paolo',
        'Q' => 'Quinn', 'R' => 'Rafael', 'S' => 'Sofia', 'T' => 'Tomas',
        'U' => 'Uma', 'V' => 'Victor', 'W' => 'Wendy', 'X' => 'Xavier',
        'Y' => 'Yvonne', 'Z' => 'Zara',
    ];

    /** @param list<string> $aliases */
    public static function displayName(array $aliases): string
    {
        $candidates = array_values(array_filter(array_map(
            static fn (string $alias): string => self::cleanAlias($alias),
            $aliases,
        )));

        if ($candidates === []) {
            return 'Faculty Member';
        }

        usort($candidates, static function (string $left, string $right): int {
            $score = static function (string $candidate): array {
                $tokens = self::tokens($candidate);
                $completeTokens = count(array_filter($tokens, static fn (string $token): bool => strlen($token) > 1));

                return [$completeTokens, count($tokens), strlen($candidate), $candidate];
            };

            return $score($right) <=> $score($left);
        });

        $tokens = self::tokens($candidates[0]);
        if (count($tokens) === 1) {
            return self::fallbackGivenName($tokens[0]).' '.self::titleCase($tokens[0]);
        }

        return implode(' ', array_map(static function (string $token, int $index): string {
            $letters = strtoupper(str_replace('.', '', $token));
            if ($index === 0 && strlen($letters) === 1) {
                return self::INITIAL_EXPANSIONS[$letters];
            }
            if ($index === 0 && strlen($letters) === 2 && ctype_alpha($letters)) {
                return self::INITIAL_EXPANSIONS[$letters[0]].' '.self::INITIAL_EXPANSIONS[$letters[1]];
            }
            if (strlen($letters) === 1) {
                return $letters.'.';
            }

            return self::titleCase($token);
        }, $tokens, array_keys($tokens)));
    }

    public static function identityKey(string $alias): string
    {
        $tokens = self::tokens(self::cleanAlias($alias));
        if ($tokens === []) {
            return 'unknown';
        }

        $surname = self::surname($tokens);
        if (count($tokens) === 1) {
            return self::keyPart($surname).':source-only';
        }

        return self::keyPart($surname).':'.strtolower($tokens[0][0]);
    }

    private static function cleanAlias(string $alias): string
    {
        $value = trim($alias);
        $value = preg_replace('/\([^)]*\)/u', '', $value) ?? $value;
        $value = preg_replace('/\b(?:MR|MRS|MS|DR|ATTY|COACH|PROF)\.?\s*/iu', '', $value) ?? $value;
        $value = preg_replace('/\bJR\.?\b/iu', ' Jr', $value) ?? $value;
        $value = preg_replace('/\s*,\s*/u', ', ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        $parts = array_values(array_filter(array_map('trim', explode(',', $value))));
        if (count($parts) >= 2) {
            $value = implode(' ', array_merge(array_slice($parts, 1), [$parts[0]]));
        }

        $value = preg_replace('/[^\p{L}\s.\-]/u', '', $value) ?? $value;

        return trim($value);
    }

    /** @return list<string> */
    private static function tokens(string $value): array
    {
        return array_values(array_filter(preg_split('/\s+/u', trim($value)) ?: []));
    }

    /** @param list<string> $tokens */
    private static function surname(array $tokens): string
    {
        $withoutSuffix = array_values(array_filter($tokens, static fn (string $token): bool => ! in_array(strtoupper(trim($token, '.')), ['JR', 'SR', 'II', 'III'], true)));
        $last = $withoutSuffix[array_key_last($withoutSuffix)] ?? $tokens[array_key_last($tokens)];
        $previous = $withoutSuffix[count($withoutSuffix) - 2] ?? null;
        if ($previous !== null && in_array(strtoupper(trim($previous, '.')), ['DE', 'DELA', 'DEL', 'DA', 'LA', 'VAN', 'VON'], true)) {
            return $previous.' '.$last;
        }

        return $last;
    }

    private static function fallbackGivenName(string $surname): string
    {
        $index = abs((int) crc32(strtolower($surname))) % count(self::FALLBACK_GIVEN_NAMES);

        return self::FALLBACK_GIVEN_NAMES[$index];
    }

    private static function keyPart(string $value): string
    {
        return strtolower((string) preg_replace('/[^\p{L}]/u', '', $value));
    }

    private static function titleCase(string $value): string
    {
        $lower = mb_strtolower($value);
        $parts = preg_split('/(-)/u', $lower, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        return implode('', array_map(static fn (string $part): string => $part === '-'
            ? $part
            : mb_strtoupper(mb_substr($part, 0, 1)).mb_substr($part, 1), $parts));
    }
}
