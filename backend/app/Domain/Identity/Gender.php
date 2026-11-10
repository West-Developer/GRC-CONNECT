<?php

namespace App\Domain\Identity;

/**
 * A student's gender as the CHED forms record it (ADR 0049): two values, stored as one letter.
 * The CHED Enrollment List writes `M` / `F`; the NSTP Form 2-B lists write `MALE` / `FEMALE`
 * because the template's footer counts those words.
 */
enum Gender: string
{
    case Male = 'M';
    case Female = 'F';

    public function letter(): string
    {
        return $this->value;
    }

    public function word(): string
    {
        return match ($this) {
            self::Male => 'MALE',
            self::Female => 'FEMALE',
        };
    }
}
