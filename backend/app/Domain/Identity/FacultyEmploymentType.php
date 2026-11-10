<?php

namespace App\Domain\Identity;

/** Local planning metadata; it is not a production HR employment record. */
enum FacultyEmploymentType: string
{
    case FullTime = 'full_time';
    case PartTime = 'part_time';

    public function label(): string
    {
        return match ($this) {
            self::FullTime => 'Full-time',
            self::PartTime => 'Part-time',
        };
    }

    public function planningUnitReference(): ?int
    {
        return match ($this) {
            self::FullTime => 33,
            self::PartTime => null,
        };
    }
}
