<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Enrollment\EnrollmentBlock;
use App\Models\Section;
use App\Support\Http\ProfessorDisclosure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read EnrollmentBlock $resource
 */
final class EnrollmentBlockResource extends JsonResource
{
    /**
     * Exact key set. No attribute is passed through implicitly. `capacity`
     * is the block-wide seat count only when every section shares one —
     * otherwise `null`, since `seats_remaining` (the MIN) is what actually
     * governs selectability.
     *
     * @return array{
     *     type: string,
     *     block_code: string,
     *     year_level: int,
     *     curriculum_id: int,
     *     section_plan_id: ?int,
     *     total_units: float,
     *     seats_remaining: int,
     *     capacity: ?int,
     *     is_selectable: bool,
     *     reasons: list<array{code: string, message: string}>,
     *     preference_score: ?int,
     *     preference_reasons: list<string>,
     *     subjects: list<array{
     *         section_id: int,
     *         subject_id: int,
     *         code: string,
     *         title: string,
     *         units: float,
     *         schedule_days: ?string,
     *         starts_at_time: ?string,
     *         ends_at_time: ?string,
     *         room: ?string,
     *         modality: ?string,
     *         professor_name: ?string,
     *         capacity: int,
     *         enrolled_count: int,
     *         remaining_seats: int
     *     }>
     * }
     */
    public function toArray(Request $request): array
    {
        $capacities = array_unique(array_map(fn (Section $section): int => $section->capacity, $this->resource->sections));

        return [
            'type' => 'enrollment_block',
            'block_code' => $this->resource->blockCode,
            'year_level' => $this->resource->yearLevel,
            'curriculum_id' => $this->resource->curriculumId,
            'section_plan_id' => $this->resource->sectionPlanId,
            'total_units' => $this->resource->totalUnits,
            'seats_remaining' => $this->resource->seatsRemaining,
            'capacity' => count($capacities) === 1 ? $capacities[0] : null,
            'is_selectable' => $this->resource->isSelectable,
            'reasons' => $this->resource->reasons,
            'preference_score' => $this->resource->preferenceScore,
            'preference_reasons' => $this->resource->preferenceReasons,
            'subjects' => array_map(fn (Section $section): array => [
                'section_id' => $section->id,
                'subject_id' => $section->subject_id,
                'paired_subject_id' => $section->subject->paired_subject_id,
                'code' => $section->subject->code,
                'title' => $section->subject->title,
                'units' => (float) $section->subject->units,
                'schedule_days' => $section->schedule_days,
                'starts_at_time' => $section->starts_at_time,
                'ends_at_time' => $section->ends_at_time,
                'room' => $section->room,
                'modality' => $section->modality?->value,
                'professor_name' => ProfessorDisclosure::hiddenFrom($request, $section->academic_term_id) ? null : $section->professor?->name,
                'capacity' => $section->capacity,
                'enrolled_count' => $section->enrolled_count,
                'remaining_seats' => $section->remainingSeats(),
            ], $this->resource->sections),
        ];
    }
}
