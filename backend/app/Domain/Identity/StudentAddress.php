<?php

namespace App\Domain\Identity;

/**
 * Admission types a student's address as street / barangay, town / city and province (the three
 * address columns of the CHED NSTP forms, ADR 0049). The single `address` text every older screen
 * and the COR read is written from those three, so there is one source and the two never disagree.
 */
final class StudentAddress
{
    public static function compose(?string $streetBarangay, ?string $townCity, ?string $province): string
    {
        $parts = array_filter(
            [trim((string) $streetBarangay), trim((string) $townCity), trim((string) $province)],
            static fn (string $part): bool => $part !== '',
        );

        return implode(', ', $parts);
    }
}
