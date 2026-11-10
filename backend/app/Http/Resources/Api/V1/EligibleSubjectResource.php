<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Enrollment\EligibleSubjectEntry;
use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read EligibleSubjectEntry $resource
 */
final class EligibleSubjectResource extends JsonResource
{
    /**
     * Exact key set. No attribute is passed through implicitly.
     *
     * Each `available_sections` entry is a `SectionResource` payload plus the
     * computed keys `college`, `is_own_department`, `subject_code` and
     * `subject_title` — the section's OWN subject, which differs from the
     * placement's subject whenever the section was sourced from a sibling
     * college's identical (same code + units) row. The `mixed` below predates
     * these keys and is left imprecise deliberately.
     *
     * @return array{
     *     type: string,
     *     subject_id: int,
     *     code: string,
     *     title: string,
     *     units: float,
     *     paired_subject_id: ?int,
     *     year_level: int,
     *     semester: string,
     *     is_required: bool,
     *     is_eligible: bool,
     *     reasons: list<array{code: string, message: string}>,
     *     preference_score: ?int,
     *     preference_reasons: list<string>,
     *     available_sections: mixed
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'eligible_subject',
            'subject_id' => $this->resource->subject->id,
            'code' => $this->resource->subject->code,
            'title' => $this->resource->subject->title,
            'units' => $this->resource->subject->units,
            'paired_subject_id' => $this->resource->subject->paired_subject_id,
            'year_level' => $this->resource->placement->year_level,
            'semester' => $this->resource->placement->semester,
            'is_required' => $this->resource->placement->is_required,
            'is_eligible' => $this->resource->isEligible,
            'reasons' => $this->resource->reasons,
            'preference_score' => $this->resource->preferenceScore,
            'preference_reasons' => $this->resource->preferenceReasons,
            'available_sections' => array_map(
                fn (Section $section): array => [
                    ...(new SectionResource($section))->resolve($request),
                    'college' => $section->subject->college?->value,
                    'is_own_department' => $section->subject_id === $this->resource->subject->id,
                    'subject_code' => $section->subject->code,
                    'subject_title' => $section->subject->title,
                ],
                $this->resource->availableSections,
            ),
        ];
    }
}
