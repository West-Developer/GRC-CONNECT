<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\SubjectPrerequisite;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Assumes `subjectPlacements.subject` and
 * `subjectPlacements.prerequisites.prerequisiteSubject` are eager-loaded by
 * the caller (PRD §10.6 forbids N+1 loading).
 *
 * @property-read Curriculum $resource
 */
final class CurriculumResource extends JsonResource
{
    /**
     * Exact key set. No attribute is passed through implicitly.
     *
     * @return array{
     *     type: string,
     *     id: int,
     *     program_id: int,
     *     equivalency_source_curriculum_id: ?int,
     *     equivalency_source_curriculum_name: ?string,
     *     name: string,
     *     effective_school_year: string,
     *     status: string,
     *     status_label: string,
     *     max_units: ?float,
     *     default_max_units: float,
     *     effective_max_units: float,
     *     year_level_max_units: array<int, float>,
     *     decided_at: ?string,
     *     last_decision_reason: ?string,
     *     subjects: list<array<string, mixed>>
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'curriculum',
            'id' => $this->resource->id,
            'program_id' => $this->resource->program_id,
            'equivalency_source_curriculum_id' => $this->resource->equivalency_source_curriculum_id,
            'equivalency_source_curriculum_name' => $this->resource->equivalencySourceCurriculum?->name,
            'name' => $this->resource->name,
            'effective_school_year' => $this->resource->effective_school_year,
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
            'max_units' => $this->resource->max_units !== null ? (float) $this->resource->max_units : null,
            'default_max_units' => $this->resource->defaultMaxUnits(),
            'effective_max_units' => $this->resource->effectiveMaxUnits(),
            'year_level_max_units' => $this->resource->yearLevelMaxUnits(),
            'decided_at' => $this->resource->decided_at?->toIso8601String(),
            'last_decision_reason' => $this->resource->last_decision_reason,
            'subjects' => array_values($this->resource->subjectPlacements
                ->map(fn (CurriculumSubject $placement): array => $this->placementToArray($placement))
                ->all()),
        ];
    }

    /**
     * @return array{
     *     subject_id: int,
     *     code: string,
     *     title: string,
     *     units: float,
     *     year_level: int,
     *     semester: string,
     *     is_required: bool,
     *     equivalent_source_subject_id: ?int,
     *     equivalent_source_subject_code: ?string,
     *     equivalent_source_subject_title: ?string,
     *     prerequisites: list<array{prerequisite_subject_id: int, code: string, minimum_grade: string}>
     * }
     */
    private function placementToArray(CurriculumSubject $placement): array
    {
        $equivalency = $this->resource->targetEquivalencies
            ->firstWhere('target_subject_id', $placement->subject_id);

        return [
            'subject_id' => $placement->subject_id,
            'code' => $placement->subject->code,
            'title' => $placement->subject->title,
            'units' => (float) $placement->subject->units,
            'year_level' => $placement->year_level,
            'semester' => $placement->semester,
            'is_required' => $placement->is_required,
            'equivalent_source_subject_id' => $equivalency?->source_subject_id,
            'equivalent_source_subject_code' => $equivalency?->sourceSubject?->code,
            'equivalent_source_subject_title' => $equivalency?->sourceSubject?->title,
            'prerequisites' => array_values($placement->prerequisites
                ->map(fn (SubjectPrerequisite $prerequisite): array => [
                    'prerequisite_subject_id' => $prerequisite->prerequisite_subject_id,
                    'code' => $prerequisite->prerequisiteSubject->code,
                    'minimum_grade' => $prerequisite->minimum_grade,
                ])
                ->all()),
        ];
    }
}
