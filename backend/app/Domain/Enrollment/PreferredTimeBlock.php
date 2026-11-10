<?php

namespace App\Domain\Enrollment;

/** The time-of-day window a student would prefer their classes to fall in. */
enum PreferredTimeBlock: string
{
    case Morning = 'morning';
    case Afternoon = 'afternoon';
    case Evening = 'evening';
    case Any = 'any';

    public function label(): string
    {
        return match ($this) {
            self::Morning => 'Morning',
            self::Afternoon => 'Afternoon',
            self::Evening => 'Evening',
            self::Any => 'No Preference',
        };
    }
}
