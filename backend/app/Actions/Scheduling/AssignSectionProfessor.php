<?php

namespace App\Actions\Scheduling;

use App\Domain\Audit\AuditRequestContext;
use App\Domain\Scheduling\SectionAssignmentConflicts;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Dean assigns, changes, or removes the professor of one section in their
 * own college (stakeholder Doc 14, ADR 0033). It changes the professor and
 * nothing else, refuses a professor who is already teaching at that time, and
 * runs through `UpdateSection` so the audit row, the professor notice, and,
 * for a published section, the Registrar Head notice are exactly what a
 * Program Head's change would produce. Assigning who is already assigned
 * changes nothing.
 */
final readonly class AssignSectionProfessor
{
    public function __construct(
        private UpdateSection $updateSection,
        private SectionAssignmentConflicts $conflicts,
    ) {}

    public function execute(
        User $actor,
        Section $section,
        ?int $professorId,
        ?string $reason,
        AuditRequestContext $context,
    ): Section {
        return DB::transaction(function () use ($actor, $section, $professorId, $reason, $context): Section {
            $section = Section::query()->lockForUpdate()->findOrFail($section->id);

            if ($section->professor_id === $professorId) {
                return $section;
            }

            if ($professorId !== null) {
                SaveFacultyLoadOverride::assertProfessorInCollege($actor, User::query()->findOrFail($professorId));

                $clash = $this->conflicts->errorsFor($section, [
                    'academic_term_id' => $section->academic_term_id,
                    'section_code' => $section->section_code,
                    'schedule_days' => $section->schedule_days,
                    'starts_at_time' => $section->starts_at_time,
                    'ends_at_time' => $section->ends_at_time,
                    'professor_id' => $professorId,
                    'room' => null,
                    'modality' => null,
                ]);

                if (isset($clash['professor_id'])) {
                    throw ValidationException::withMessages(['professor_id' => $clash['professor_id']]);
                }
            }

            if ($section->recommendation_prediction_run_id !== null && ! filled($reason)) {
                throw ValidationException::withMessages([
                    'override_reason' => 'Explain why this generated assignment is being overridden.',
                ]);
            }

            return $this->updateSection->execute($actor, [
                'academic_term_id' => $section->academic_term_id,
                'subject_id' => $section->subject_id,
                'section_code' => $section->section_code,
                'professor_id' => $professorId,
                'schedule_days' => $section->schedule_days,
                'starts_at_time' => $section->starts_at_time,
                'ends_at_time' => $section->ends_at_time,
                'room' => $section->room,
                'modality' => $section->modality?->value,
                'capacity' => $section->capacity,
                'viability_threshold' => $section->viability_threshold,
                'status' => $section->status->value,
                'override_reason' => filled($reason) ? $reason : null,
            ], $section, $context);
        });
    }
}
