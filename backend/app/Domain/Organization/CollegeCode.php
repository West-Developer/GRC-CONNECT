<?php

namespace App\Domain\Organization;

/**
 * The four colleges currently supported by the system, per explicit user
 * scope decision. Any program/subject/user outside these four (e.g. the
 * already-inactive BS Criminology) simply carries a null `college` — this
 * enum is deliberately not exhaustive of every college GRC may ever offer.
 */
enum CollegeCode: string
{
    case Ccs = 'ccs';
    case Coe = 'coe';
    case Coa = 'coa';
    case Cbae = 'cbae';

    public function label(): string
    {
        return match ($this) {
            self::Ccs => 'College of Computer Studies',
            self::Coe => 'College of Education',
            self::Coa => 'College of Accountancy',
            self::Cbae => 'College of Business Administration and Entrepreneurship',
        };
    }
}
