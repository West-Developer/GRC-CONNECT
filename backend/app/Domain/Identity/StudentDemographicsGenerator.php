<?php

namespace App\Domain\Identity;

use Carbon\CarbonImmutable;

/**
 * Generated demo values for the student details the system never stored (ADR 0049). Everything
 * is derived from the student profile's id (and entry year), so the same student always gets the
 * same values and a second run of `students:populate-demographics` never changes anything.
 * These are NOT real data: the command records every field it fills in
 * `student_profiles.demographics_demo_fields`.
 */
final class StudentDemographicsGenerator
{
    public const CITIZENSHIP = 'Filipino';

    /** Mobile prefixes in use in the Philippines (0917, 0918, ...). */
    private const MOBILE_PREFIXES = ['0917', '0918', '0919', '0920', '0921', '0927', '0928', '0935', '0936', '0945', '0955', '0956', '0965', '0966', '0975', '0977', '0995', '0997'];

    /** @var list<array{0: string, 1: string}> town / province, Caloocan weighted: the school is there */
    private const TOWNS = [
        ['Caloocan City', 'Metro Manila'], ['Caloocan City', 'Metro Manila'], ['Caloocan City', 'Metro Manila'],
        ['Quezon City', 'Metro Manila'], ['Malabon City', 'Metro Manila'], ['Navotas City', 'Metro Manila'],
        ['Valenzuela City', 'Metro Manila'], ['Manila', 'Metro Manila'], ['Marikina City', 'Metro Manila'],
        ['Pasig City', 'Metro Manila'], ['Meycauayan City', 'Bulacan'], ['San Jose del Monte City', 'Bulacan'],
        ['Marilao', 'Bulacan'], ['Bocaue', 'Bulacan'], ['Antipolo City', 'Rizal'], ['Cainta', 'Rizal'],
        ['Taytay', 'Rizal'], ['Bacoor City', 'Cavite'], ['Imus City', 'Cavite'], ['Dasmarinas City', 'Cavite'],
    ];

    private const STREETS = ['Rizal', 'Mabini', 'Bonifacio', 'Luna', 'Del Pilar', 'Quezon', 'Aguinaldo', 'Burgos', 'Roxas', 'Magsaysay', 'Sampaguita', 'Rosal', 'Narra', 'Mahogany', 'Kalayaan'];

    private const BARANGAYS = ['San Jose', 'Poblacion', 'Santa Cruz', 'San Roque', 'Bagong Silang', 'Maypajo', 'Longos', 'Tangos', 'Santa Maria', 'San Isidro', 'Bagumbayan', 'Concepcion'];

    public function citizenship(): string
    {
        return self::CITIZENSHIP;
    }

    /**
     * A student's age at entry is 17 to 21; the year is the entry year, or this year minus the
     * years already completed when the entry year is not recorded.
     */
    public function birthDate(int $seed, ?int $entryYear, int $yearLevel): CarbonImmutable
    {
        $baseYear = $entryYear ?? ((int) now()->format('Y') - max(0, $yearLevel - 1));
        $age = 17 + $this->pick($seed, 'birth-age', 5);

        return CarbonImmutable::parse(sprintf(
            '%04d-%02d-%02d',
            $baseYear - $age,
            1 + $this->pick($seed, 'birth-month', 12),
            1 + $this->pick($seed, 'birth-day', 28),
        ));
    }

    public function contactNumber(int $seed): string
    {
        $prefix = self::MOBILE_PREFIXES[$this->pick($seed, 'mobile-prefix', count(self::MOBILE_PREFIXES))];

        return $prefix.str_pad((string) $this->pick($seed, 'mobile-number', 10_000_000), 7, '0', STR_PAD_LEFT);
    }

    /**
     * @return array{street_barangay: string, town_city: string, province: string}
     */
    public function address(int $seed): array
    {
        [$town, $province] = self::TOWNS[$this->pick($seed, 'town', count(self::TOWNS))];
        $number = 1 + $this->pick($seed, 'house', 250);
        $street = self::STREETS[$this->pick($seed, 'street', count(self::STREETS))];
        $barangay = self::BARANGAYS[$this->pick($seed, 'barangay', count(self::BARANGAYS))];

        return [
            'street_barangay' => "{$number} {$street} St., Brgy. {$barangay}",
            'town_city' => $town,
            'province' => $province,
        ];
    }

    /**
     * The last resort for a first name nobody can read a gender from.
     */
    public function fallbackGender(int $seed): Gender
    {
        return $this->pick($seed, 'gender', 2) === 0 ? Gender::Female : Gender::Male;
    }

    private function pick(int $seed, string $salt, int $modulo): int
    {
        return crc32("{$seed}:{$salt}") % $modulo;
    }
}
