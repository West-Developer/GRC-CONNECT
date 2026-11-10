<?php

namespace App\Domain\Faculty;

enum SpecializationProficiency: string
{
    case Primary = 'primary';
    case Secondary = 'secondary';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Primary',
            self::Secondary => 'Secondary',
        };
    }
}
