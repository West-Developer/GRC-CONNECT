<?php

namespace App\Domain\Identity;

/**
 * What one run of `students:populate-demographics` did (or, in a dry run, would do): how many
 * students had something missing, how many were filled, how many students got each field, and how
 * each gender was decided.
 */
final class DemographicsPopulationReport
{
    public const BY_NAME = 'name';

    public const BY_ENDING = 'ending';

    public const BY_FALLBACK = 'fallback';

    public int $considered = 0;

    public int $updated = 0;

    /** @var array<string, int> */
    public array $fields = [];

    public int $genderFromName = 0;

    public int $genderFromEnding = 0;

    public int $genderFallback = 0;

    /**
     * @param  list<string>  $fields  the field names filled for one student
     */
    public function filled(array $fields, ?string $genderSource): void
    {
        $this->updated++;

        foreach ($fields as $field) {
            $this->fields[$field] = ($this->fields[$field] ?? 0) + 1;
        }

        match ($genderSource) {
            self::BY_NAME => $this->genderFromName++,
            self::BY_ENDING => $this->genderFromEnding++,
            self::BY_FALLBACK => $this->genderFallback++,
            default => null,
        };
    }

    /**
     * @return array{considered: int, updated: int, fields: array<string, int>, gender: array{name: int, ending: int, fallback: int}}
     */
    public function toArray(): array
    {
        $fields = $this->fields;
        ksort($fields);

        return [
            'considered' => $this->considered,
            'updated' => $this->updated,
            'fields' => $fields,
            'gender' => [
                self::BY_NAME => $this->genderFromName,
                self::BY_ENDING => $this->genderFromEnding,
                self::BY_FALLBACK => $this->genderFallback,
            ],
        ];
    }
}
