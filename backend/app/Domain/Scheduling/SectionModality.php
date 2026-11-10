<?php

namespace App\Domain\Scheduling;

enum SectionModality: string
{
    case HyflexA = 'hyflex_a';
    case HyflexB = 'hyflex_b';
    case FaceToFace = 'f2f';

    public function label(): string
    {
        return match ($this) {
            self::HyflexA => 'Hyflex A',
            self::HyflexB => 'Hyflex B',
            self::FaceToFace => 'F2F',
        };
    }
}
