<?php

namespace App\Actions\Scheduling;

use App\Domain\Scheduling\SectionAssignmentConflicts;
use App\Models\Section;

/**
 * Checks a proposed change against a section as it stands now: the times must
 * still make sense together, capacity cannot drop below the students already
 * enrolled, and the new slot must not clash with another class. Shared by the
 * moment a change is requested and the moment it is approved.
 */
final class SectionChangeApplication
{
    /**
     * @param  array<string, mixed>  $newValues  the fields being changed
     * @return array<string, string> validation message keyed by field
     */
    public static function problemsFor(Section $section, array $newValues, SectionAssignmentConflicts $conflicts): array
    {
        $merged = self::merged($section, $newValues);
        $errors = [];

        if (($merged['starts_at_time'] === null) !== ($merged['ends_at_time'] === null)) {
            $errors['starts_at_time'] = 'Give both a start and an end time, or neither.';
        } elseif ($merged['starts_at_time'] !== null && (string) $merged['ends_at_time'] <= (string) $merged['starts_at_time']) {
            $errors['ends_at_time'] = 'The end time must be after the start time.';
        }

        if ($merged['capacity'] < $section->enrolled_count) {
            $errors['capacity'] = "Capacity cannot be below the {$section->enrolled_count} students already enrolled in this section.";
        }

        return $errors + $conflicts->errorsFor($section, [
            'academic_term_id' => $section->academic_term_id,
            'section_code' => $section->section_code,
            'schedule_days' => $merged['schedule_days'],
            'starts_at_time' => $merged['starts_at_time'],
            'ends_at_time' => $merged['ends_at_time'],
            'professor_id' => $section->professor_id,
            'room' => $merged['room'],
            'modality' => $merged['modality'],
        ]);
    }

    /**
     * The editable schedule fields of a section with the proposed ones laid
     * over them.
     *
     * @param  array<string, mixed>  $newValues
     * @return array{schedule_days: mixed, starts_at_time: mixed, ends_at_time: mixed, room: mixed, modality: mixed, capacity: int, viability_threshold: mixed}
     */
    public static function merged(Section $section, array $newValues): array
    {
        $current = [
            'schedule_days' => $section->schedule_days,
            'starts_at_time' => $section->starts_at_time,
            'ends_at_time' => $section->ends_at_time,
            'room' => $section->room,
            'modality' => $section->modality?->value,
            'capacity' => $section->capacity,
            'viability_threshold' => $section->viability_threshold,
        ];
        $merged = array_replace($current, array_intersect_key($newValues, $current));
        $merged['capacity'] = (int) $merged['capacity'];

        return $merged;
    }
}
