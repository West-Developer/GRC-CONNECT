<?php

namespace App\Domain\Scheduling;

use App\Models\Section;

/**
 * The three clashes a section's schedule can create: two subjects of the same
 * block section overlapping, a professor teaching two classes at once, and a
 * room hosting two classes at once. One implementation shared by the section
 * edit request and by a Registrar Head approving a change request against a
 * section that may have moved since the request was made.
 */
final readonly class SectionAssignmentConflicts
{
    public function __construct(
        private SectionConflictDetector $sectionConflicts,
        private RoomConflictDetector $roomConflicts,
    ) {}

    /**
     * @param  array{academic_term_id: int|string|null, section_code: ?string, schedule_days: ?string, starts_at_time: ?string, ends_at_time: ?string, professor_id: int|string|null, room: ?string, modality: ?string}  $proposed
     * @return array<string, string> validation message keyed by the offending field
     */
    public function errorsFor(Section $section, array $proposed): array
    {
        $errors = [];

        if ($intra = $this->intraSectionConflict($section, $proposed)) {
            $subjectCode = $intra->subject->code;
            $errors['schedule_days'] = "Schedule conflicts with {$subjectCode} in block section {$proposed['section_code']} ({$intra->schedule_days} {$intra->starts_at_time}-{$intra->ends_at_time}).";
        }

        if ($professor = $this->professorConflict($section, $proposed)) {
            $subjectCode = $professor->subject->code;
            $errors['professor_id'] = "This professor is already assigned to {$professor->section_code} ({$subjectCode}) on {$professor->schedule_days} {$professor->starts_at_time}-{$professor->ends_at_time}.";
        }

        if ($room = $this->roomConflict($section, $proposed)) {
            $subjectCode = $room->subject->code;
            $errors['room'] = "Room {$proposed['room']} is already occupied by {$room->section_code} ({$subjectCode}) on {$room->schedule_days} {$room->starts_at_time}-{$room->ends_at_time}.";
        }

        return $errors;
    }

    /** @param  array{academic_term_id: int|string|null, section_code: ?string, schedule_days: ?string, starts_at_time: ?string, ends_at_time: ?string, professor_id: int|string|null, room: ?string, modality: ?string}  $proposed */
    private function intraSectionConflict(Section $section, array $proposed): ?Section
    {
        if (! $proposed['section_code'] || ! $proposed['schedule_days'] || ! $proposed['starts_at_time'] || ! $proposed['ends_at_time']) {
            return null;
        }

        $others = Section::query()
            ->where('academic_term_id', $proposed['academic_term_id'])
            ->where('section_code', $proposed['section_code'])
            ->whereKeyNot($section->id)
            ->whereNotNull('schedule_days')
            ->whereNotNull('starts_at_time')
            ->whereNotNull('ends_at_time')
            ->with('subject')
            ->get();

        foreach ($others as $other) {
            if ($this->sectionConflicts->hasConflict(self::proposedSlot($proposed), [self::sectionSlot($other)])) {
                return $other;
            }
        }

        return null;
    }

    /** @param  array{academic_term_id: int|string|null, section_code: ?string, schedule_days: ?string, starts_at_time: ?string, ends_at_time: ?string, professor_id: int|string|null, room: ?string, modality: ?string}  $proposed */
    private function professorConflict(Section $section, array $proposed): ?Section
    {
        if (! is_numeric($proposed['professor_id'])) {
            return null;
        }

        $others = Section::query()
            ->where('professor_id', $proposed['professor_id'])
            ->where('academic_term_id', $proposed['academic_term_id'])
            ->whereKeyNot($section->id)
            ->whereNotNull('schedule_days')
            ->whereNotNull('starts_at_time')
            ->whereNotNull('ends_at_time')
            ->with('subject')
            ->get();

        foreach ($others as $other) {
            if ($this->sectionConflicts->hasConflict(self::proposedSlot($proposed), [self::sectionSlot($other)])) {
                return $other;
            }
        }

        return null;
    }

    /** @param  array{academic_term_id: int|string|null, section_code: ?string, schedule_days: ?string, starts_at_time: ?string, ends_at_time: ?string, professor_id: int|string|null, room: ?string, modality: ?string}  $proposed */
    private function roomConflict(Section $section, array $proposed): ?Section
    {
        $room = $proposed['room'];
        if (! is_string($room) || trim($room) === '') {
            return null;
        }

        $others = Section::query()
            ->where('room', $room)
            ->where('academic_term_id', $proposed['academic_term_id'])
            ->whereKeyNot($section->id)
            ->whereNotNull('schedule_days')
            ->whereNotNull('starts_at_time')
            ->whereNotNull('ends_at_time')
            ->with('subject')
            ->get();

        foreach ($others as $other) {
            if ($this->roomConflicts->hasConflict(self::proposedSlot($proposed), [self::sectionSlot($other)])) {
                return $other;
            }
        }

        return null;
    }

    /**
     * @param  array{schedule_days: ?string, starts_at_time: ?string, ends_at_time: ?string, modality: ?string}  $proposed
     * @return array{schedule_days: ?string, starts_at_time: ?string, ends_at_time: ?string, modality: ?string}
     */
    private static function proposedSlot(array $proposed): array
    {
        return [
            'schedule_days' => $proposed['schedule_days'],
            'starts_at_time' => $proposed['starts_at_time'],
            'ends_at_time' => $proposed['ends_at_time'],
            'modality' => $proposed['modality'],
        ];
    }

    /** @return array{schedule_days: ?string, starts_at_time: ?string, ends_at_time: ?string, modality: ?string} */
    private static function sectionSlot(Section $section): array
    {
        return [
            'schedule_days' => $section->schedule_days,
            'starts_at_time' => $section->starts_at_time,
            'ends_at_time' => $section->ends_at_time,
            'modality' => $section->modality?->value,
        ];
    }
}
