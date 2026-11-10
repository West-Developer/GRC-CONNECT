<?php

namespace App\Actions\Scheduling;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Organization\CapacitySource;
use App\Domain\Scheduling\CanonicalScheduleDays;
use App\Models\Section;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

final class CreateSection
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly CanonicalScheduleDays $canonicalScheduleDays,
    ) {}

    /**
     * @param  array{
     *     academic_term_id: int,
     *     subject_id: int,
     *     section_code: string,
     *     professor_id: ?int,
     *     schedule_days: ?string,
     *     starts_at_time: ?string,
     *     ends_at_time: ?string,
     *     room: ?string,
     *     modality: ?string,
     *     capacity: int,
     *     viability_threshold: ?int,
     *     status: string
     * }  $validatedData
     */
    public function execute(User $actor, array $validatedData, AuditRequestContext $context): Section
    {
        return DB::transaction(function () use ($actor, $validatedData, $context): Section {
            $section = Section::create([
                'academic_term_id' => $validatedData['academic_term_id'],
                'subject_id' => $validatedData['subject_id'],
                'section_code' => $validatedData['section_code'],
                'professor_id' => $validatedData['professor_id'],
                'schedule_days' => $this->canonicalScheduleDays->normalize($validatedData['schedule_days']),
                'starts_at_time' => $validatedData['starts_at_time'],
                'ends_at_time' => $validatedData['ends_at_time'],
                'room' => $validatedData['room'],
                'modality' => $validatedData['modality'] ?? null,
                'capacity' => $validatedData['capacity'],
                // Hand-created sections are not generated from a year-level
                // plan, so their capacity is the author's, not the plan's.
                'capacity_source' => CapacitySource::Manual,
                'viability_threshold' => $validatedData['viability_threshold'],
                'status' => $validatedData['status'],
            ]);
            $section->refresh();

            $this->auditRecorder->record(
                $actor,
                AuditAction::SECTION_CREATED,
                AuditableType::SECTION,
                $section->id,
                null,
                self::snapshot($section),
                null,
                $context,
            );

            return $section;
        });
    }

    /** @return array{academic_term_id: int, subject_id: int, section_code: string, professor_id: ?int, schedule_days: ?string, starts_at_time: ?string, ends_at_time: ?string, room: ?string, modality: ?string, capacity: int, capacity_source: string, viability_threshold: ?int, enrolled_count: int, status: string} */
    private static function snapshot(Section $section): array
    {
        return [
            'academic_term_id' => $section->academic_term_id,
            'subject_id' => $section->subject_id,
            'section_code' => $section->section_code,
            'professor_id' => $section->professor_id,
            'schedule_days' => $section->schedule_days,
            'starts_at_time' => $section->starts_at_time,
            'ends_at_time' => $section->ends_at_time,
            'room' => $section->room,
            'modality' => $section->modality?->value,
            'capacity' => $section->capacity,
            'capacity_source' => $section->capacity_source->value,
            'viability_threshold' => $section->viability_threshold,
            'enrolled_count' => $section->enrolled_count,
            'status' => $section->status->value,
        ];
    }
}
