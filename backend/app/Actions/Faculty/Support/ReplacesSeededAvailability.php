<?php

namespace App\Actions\Faculty\Support;

use App\Models\FacultyAvailability;

/**
 * Shared collision-avoidance for declared faculty availability writes: a
 * professor may create or edit a declared window onto a day/time slot that
 * is still occupied by a `workbook_seeded` row. The DB unique index on
 * (professor_id, day_of_week, starts_at_time) has no `origin` component, so
 * the seeded row must be removed before the declared write lands there.
 */
final class ReplacesSeededAvailability
{
    public static function deleteOccupyingSeededSlot(
        int $professorId,
        int $dayOfWeek,
        string $startsAtTime,
        ?int $excludingAvailabilityId = null,
    ): void {
        FacultyAvailability::query()
            ->where('professor_id', $professorId)
            ->where('day_of_week', $dayOfWeek)
            ->where('starts_at_time', $startsAtTime)
            ->where('origin', 'workbook_seeded')
            ->when(
                $excludingAvailabilityId !== null,
                static fn ($query) => $query->where('id', '!=', $excludingAvailabilityId),
            )
            ->delete();
    }
}
