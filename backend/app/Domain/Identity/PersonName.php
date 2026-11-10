<?php

namespace App\Domain\Identity;

/**
 * Composes the single display `name` stored on `users` from its structured
 * parts. Every write path that accepts first/middle/last/suffix input calls
 * this instead of letting each Action format the string its own way, so
 * "First M. Last Suffix" spacing/punctuation stays identical everywhere.
 */
final class PersonName
{
    public static function compose(
        string $firstName,
        ?string $middleInitial,
        string $lastName,
        ?string $suffix,
    ): string {
        $middle = trim((string) $middleInitial);
        $parts = [
            trim($firstName),
            $middle !== '' ? rtrim($middle, '.').'.' : null,
            trim($lastName),
            trim((string) $suffix) !== '' ? trim((string) $suffix) : null,
        ];

        return implode(' ', array_filter($parts, fn (?string $part): bool => $part !== null && $part !== ''));
    }

    /**
     * What a full middle name is shown as wherever a name is displayed ("Ramga" is "R."). Only
     * Admission and the student themself ever see the full middle name.
     */
    public static function initial(?string $middleName): ?string
    {
        $trimmed = trim((string) $middleName);

        return $trimmed === '' ? null : mb_strtoupper(mb_substr($trimmed, 0, 1));
    }

    /**
     * The middle name and initial to store when a full middle name is submitted. The initial is
     * always derived from the name, so the two cannot disagree. A value that is the same as what
     * is stored changes nothing, however it is typed: an older account has only an initial and no
     * full name, so the form shows that initial, and sending it back must not turn it into a
     * "full middle name" (nor wipe it).
     *
     * @return array{middle_name: ?string, middle_initial: ?string}
     */
    public static function middle(?string $submitted, ?string $currentName, ?string $currentInitial): array
    {
        $name = self::normalizeNamePart($submitted);
        if ($name === null || $name === '') {
            return ['middle_name' => null, 'middle_initial' => null];
        }

        $current = $currentName ?? $currentInitial;
        $key = self::middleKey($name);
        $unchanged = $current !== null && (
            $key === self::middleKey($current)
            // A bare initial that matches the stored name's initial (an older client, an older form).
            || (mb_strlen($key) === 1 && $key === mb_strtolower((string) self::initial($current)))
        );

        return $unchanged
            ? ['middle_name' => $currentName, 'middle_initial' => $currentInitial]
            : ['middle_name' => $name, 'middle_initial' => self::initial($name)];
    }

    private static function middleKey(string $value): string
    {
        return mb_strtolower(rtrim(trim($value), '.'));
    }

    /**
     * Best-effort structured parts for a display name that was written without them (a seeder, a
     * script, an import). Same rules the split-name backfill migration applied to existing rows:
     * "SURNAME,GIVEN" or "First [Middle...] Last [Suffix]"; a single word is both first and last.
     *
     * @return array{first_name: string, middle_initial: ?string, last_name: string, suffix: ?string}
     */
    public static function split(string $fullName): array
    {
        $name = trim($fullName);

        if (str_contains($name, ',')) {
            $parts = array_map('trim', explode(',', $name, 2));
            $last = $parts[0];
            $first = trim((string) preg_replace('/\([^)]*\)/', '', $parts[1]));

            return [
                'first_name' => $first !== '' ? $first : $last,
                'middle_initial' => null,
                'last_name' => $last !== '' ? $last : $first,
                'suffix' => null,
            ];
        }

        $tokens = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [$name];

        $suffix = null;
        if (count($tokens) > 1 && preg_match('/^(Jr\.?|Sr\.?|II|III|IV|V)$/i', (string) end($tokens))) {
            $suffix = array_pop($tokens);
        }

        if (count($tokens) === 1) {
            return ['first_name' => $tokens[0], 'middle_initial' => null, 'last_name' => $tokens[0], 'suffix' => $suffix];
        }

        $first = array_shift($tokens) ?? '';
        $last = array_pop($tokens) ?? $first;
        $middleInitial = $tokens !== [] ? mb_strtoupper(mb_substr($tokens[0], 0, 1)) : null;

        return ['first_name' => $first, 'middle_initial' => $middleInitial, 'last_name' => $last, 'suffix' => $suffix];
    }

    /**
     * Title-cases a first/middle/last name part regardless of how it was
     * typed (ALL CAPS, all lowercase, mixed) — capitalizes the first letter
     * of every letter-run and lowercases the rest, leaving separators
     * (spaces, hyphens, apostrophes) untouched so "dela cruz" becomes
     * "Dela Cruz" and "o'brien" becomes "O'Brien".
     */
    public static function normalizeNamePart(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        return (string) preg_replace_callback(
            '/\p{L}[\p{L}\p{Mn}]*/u',
            static fn (array $match): string => mb_strtoupper(mb_substr($match[0], 0, 1)).mb_strtolower(mb_substr($match[0], 1)),
            $trimmed,
        );
    }

    /**
     * Same casing fix as {@see normalizeNamePart()}, except a bare roman
     * numeral (II, III, IV, V) stays fully uppercase instead of becoming
     * "Iii" — matching the suffix vocabulary already recognized elsewhere
     * (see the split-name backfill migration).
     */
    public static function normalizeSuffix(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if (preg_match('/^(I|II|III|IV|V)$/i', $trimmed) === 1) {
            return mb_strtoupper($trimmed);
        }

        return self::normalizeNamePart($trimmed);
    }
}
