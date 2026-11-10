<?php

namespace App\Http\Requests\Api\V1\Section;

use App\Domain\Identity\UserRole;
use App\Domain\Scheduling\RoomConflictDetector;
use App\Domain\Scheduling\SectionConflictDetector;
use App\Domain\Scheduling\SectionModality;
use App\Domain\Scheduling\SectionStatus;
use App\Models\Section;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `enrolled_count` is deliberately not a validated/writable field — it is a
 * maintained counter derived from `enrollment_subjects`, not an input (see
 * the sections migration's own docblock).
 */
final class StoreSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'section_code' => [
                'required',
                'string',
                'max:255',
                Rule::unique('sections')->where(
                    fn ($query) => $query
                        ->where('academic_term_id', $this->input('academic_term_id'))
                        ->where('subject_id', $this->input('subject_id')),
                ),
            ],
            'professor_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', UserRole::Faculty->value)],
            'schedule_days' => ['nullable', 'string', 'max:255'],
            'starts_at_time' => ['nullable', 'date_format:H:i:s', 'required_with:ends_at_time'],
            'ends_at_time' => ['nullable', 'date_format:H:i:s', 'after:starts_at_time', 'required_with:starts_at_time'],
            'room' => ['nullable', 'string', 'max:255'],
            'modality' => ['nullable', Rule::enum(SectionModality::class)],
            'capacity' => ['required', 'integer', 'min:1'],
            'viability_threshold' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', Rule::enum(SectionStatus::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($intraConflict = $this->hasIntraSectionConflict()) {
                $subjectCode = $intraConflict->subject?->code ?? 'another subject';
                $validator->errors()->add(
                    'schedule_days',
                    "Schedule conflicts with {$subjectCode} in block section {$this->input('section_code')} ({$intraConflict->schedule_days} {$intraConflict->starts_at_time}-{$intraConflict->ends_at_time}).",
                );
            }
            if ($profConflict = $this->hasProfessorConflict()) {
                $subjectCode = $profConflict->subject?->code ?? 'another class';
                $validator->errors()->add(
                    'professor_id',
                    "This professor is already assigned to {$profConflict->section_code} ({$subjectCode}) on {$profConflict->schedule_days} {$profConflict->starts_at_time}-{$profConflict->ends_at_time}.",
                );
            }
            if ($roomConflict = $this->hasRoomConflict()) {
                $subjectCode = $roomConflict->subject?->code ?? 'another class';
                $validator->errors()->add(
                    'room',
                    "Room {$this->input('room')} is already occupied by {$roomConflict->section_code} ({$subjectCode}) on {$roomConflict->schedule_days} {$roomConflict->starts_at_time}-{$roomConflict->ends_at_time}.",
                );
            }
        });
    }

    private function hasIntraSectionConflict(): ?Section
    {
        $sectionCode = $this->input('section_code');
        $academicTermId = $this->input('academic_term_id');
        $scheduleDays = $this->input('schedule_days');
        $startsAt = $this->input('starts_at_time');
        $endsAt = $this->input('ends_at_time');

        if (! $sectionCode || ! $scheduleDays || ! $startsAt || ! $endsAt) {
            return null;
        }

        $otherSections = Section::query()
            ->where('academic_term_id', $academicTermId)
            ->where('section_code', $sectionCode)
            ->whereNotNull('schedule_days')
            ->whereNotNull('starts_at_time')
            ->whereNotNull('ends_at_time')
            ->with('subject')
            ->get();

        $detector = app(SectionConflictDetector::class);

        foreach ($otherSections as $other) {
            if ($detector->hasConflict([
                'schedule_days' => $scheduleDays,
                'starts_at_time' => $startsAt,
                'ends_at_time' => $endsAt,
            ], [[
                'schedule_days' => $other->schedule_days,
                'starts_at_time' => $other->starts_at_time,
                'ends_at_time' => $other->ends_at_time,
            ]])) {
                return $other;
            }
        }

        return null;
    }

    private function hasProfessorConflict(): ?Section
    {
        $professorId = $this->input('professor_id');

        if (! is_numeric($professorId)) {
            return null;
        }

        $otherSections = Section::query()
            ->where('professor_id', $professorId)
            ->where('academic_term_id', $this->input('academic_term_id'))
            ->whereNotNull('schedule_days')
            ->whereNotNull('starts_at_time')
            ->whereNotNull('ends_at_time')
            ->with('subject')
            ->get();

        $detector = app(SectionConflictDetector::class);

        foreach ($otherSections as $other) {
            if ($detector->hasConflict([
                'schedule_days' => $this->input('schedule_days'),
                'starts_at_time' => $this->input('starts_at_time'),
                'ends_at_time' => $this->input('ends_at_time'),
            ], [[
                'schedule_days' => $other->schedule_days,
                'starts_at_time' => $other->starts_at_time,
                'ends_at_time' => $other->ends_at_time,
            ]])) {
                return $other;
            }
        }

        return null;
    }

    private function hasRoomConflict(): ?Section
    {
        $room = $this->input('room');
        if (! is_string($room) || trim($room) === '') {
            return null;
        }

        $otherSections = Section::query()
            ->where('room', $room)
            ->where('academic_term_id', $this->input('academic_term_id'))
            ->whereNotNull('schedule_days')
            ->whereNotNull('starts_at_time')
            ->whereNotNull('ends_at_time')
            ->with('subject')
            ->get();

        $detector = app(RoomConflictDetector::class);

        foreach ($otherSections as $other) {
            if ($detector->hasConflict([
                'schedule_days' => $this->input('schedule_days'),
                'starts_at_time' => $this->input('starts_at_time'),
                'ends_at_time' => $this->input('ends_at_time'),
                'modality' => $this->input('modality'),
            ], [[
                'schedule_days' => $other->schedule_days,
                'starts_at_time' => $other->starts_at_time,
                'ends_at_time' => $other->ends_at_time,
                'modality' => $other->modality?->value,
            ]])) {
                return $other;
            }
        }

        return null;
    }
}
