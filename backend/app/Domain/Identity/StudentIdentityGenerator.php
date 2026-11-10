<?php

namespace App\Domain\Identity;

/**
 * Deterministically generates synthetic student identities (student number,
 * email, and display name) for demo/seed enrollment rosters.
 *
 * Every value is a pure function of `$entryYear` and `$sequence`, so calling
 * `forIndex()` twice with the same arguments always returns the same result
 * — no database lookups, no randomness, and no state to keep in sync across
 * runs.
 *
 * Sequences are expected to start at 1001 per entry year, which keeps
 * generated student numbers (`{year}-06-01001` and up) clear of the small
 * reserved numbers (`{year}-06-00001` through `00100`) used by the existing
 * demo enrollment roster.
 */
final class StudentIdentityGenerator
{
    /**
     * @return array{student_number: string, email: string, name: string}
     */
    public static function forIndex(int $entryYear, int $sequence): array
    {
        [$given, $surname, $middleInitial] = self::nameParts($entryYear, $sequence);
        $name = sprintf('%s %s. %s', $given, $middleInitial, $surname);

        return [
            'student_number' => self::studentNumber($entryYear, $sequence),
            'email' => self::email($given, $surname),
            'name' => $name,
        ];
    }

    private static function studentNumber(int $entryYear, int $sequence): string
    {
        return sprintf('%d-06-%05d', $entryYear, $sequence);
    }

    private static function email(string $given, string $surname): string
    {
        $cleanGiven = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $given));
        $cleanSurname = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $surname));

        return sprintf('%s.%s@grc.com', $cleanGiven, $cleanSurname);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private static function nameParts(int $entryYear, int $sequence): array
    {
        [$given, $surname] = self::pools();

        $hash = crc32("{$entryYear}-{$sequence}");

        $givenIndex = $hash % count($given);
        $surnameIndex = intdiv($hash, 97) % count($surname);
        $middleInitial = chr(65 + intdiv($hash, 9973) % 26);

        return [$given[$givenIndex], $surname[$surnameIndex], $middleInitial];
    }

    private static function name(int $entryYear, int $sequence): string
    {
        [$given, $surname, $middleInitial] = self::nameParts($entryYear, $sequence);

        return sprintf('%s %s. %s', $given, $middleInitial, $surname);
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function pools(): array
    {
        static $pools = null;

        if ($pools === null) {
            $data = require base_path('database/seeders/data/filipino-name-pools.php');
            $pools = [$data['given'], $data['surname']];
        }

        return $pools;
    }
}
