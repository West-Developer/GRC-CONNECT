<?php

namespace App\Domain\Curriculum;

use App\Models\Curriculum;
use Illuminate\Support\Collection;

/**
 * Picks which of a program's curriculum versions a student follows, given
 * the year they entered. GRC replaces a program's curriculum roughly every
 * 5 years, but a student's own version never changes after entry -- a
 * 2023-entry student stays on the 2018-2023 curriculum even once a newer one
 * is authored, while a 2024-entry student is on the new one from day one.
 */
final class CurriculumVersion
{
    /**
     * @param  Collection<int, Curriculum>  $curricula  one program's versions
     */
    public static function resolveForEntryYear(Collection $curricula, int $entryYear): ?Curriculum
    {
        $versioned = $curricula->filter(
            fn (Curriculum $curriculum): bool => $curriculum->effective_start_year !== null,
        );

        $containing = $versioned->first(
            fn (Curriculum $curriculum): bool => $entryYear >= $curriculum->effective_start_year
                && ($curriculum->effective_end_year === null || $entryYear <= $curriculum->effective_end_year),
        );

        if ($containing !== null) {
            return $containing;
        }

        return $versioned
            ->filter(fn (Curriculum $curriculum): bool => $curriculum->effective_start_year <= $entryYear)
            ->sortByDesc('effective_start_year')
            ->first();
    }
}
