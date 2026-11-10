<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One booking inside a shared room's true occupancy, regardless of which
 * college scheduled it — the room calendar needs this to avoid showing an
 * already-occupied slot as available.
 *
 * @property-read Section $resource
 */
final class RoomOccupancyResource extends JsonResource
{
    public function __construct(Section $resource, private readonly ?string $actorCollege)
    {
        parent::__construct($resource);
    }

    /**
     * @return array{
     *     type: string,
     *     section_id: int,
     *     section_code: string,
     *     subject_code: string,
     *     subject_title: string,
     *     professor_name: ?string,
     *     schedule_days: ?string,
     *     starts_at_time: ?string,
     *     ends_at_time: ?string,
     *     modality: ?string,
     *     college: ?string,
     *     is_own_college: bool,
     *     is_lecture_component: bool
     * }
     */
    public function toArray(Request $request): array
    {
        $college = $this->resource->sectionPlan?->college;

        return [
            'type' => 'room_occupancy',
            'section_id' => $this->resource->id,
            'section_code' => $this->resource->section_code,
            'subject_code' => $this->resource->subject->code,
            'subject_title' => $this->resource->subject->title,
            'professor_name' => $this->resource->professor?->name,
            'schedule_days' => $this->resource->schedule_days,
            'starts_at_time' => $this->resource->starts_at_time,
            'ends_at_time' => $this->resource->ends_at_time,
            'modality' => $this->resource->modality?->value,
            'college' => $college,
            // Mirrors `SectionPolicy::update()`'s ownership test: a
            // college-less (hand-created) section is editable by any Program
            // Chair, same as it is for direct edits on the Schedule page.
            'is_own_college' => $college === null || $college === $this->actorCollege,
            'is_lecture_component' => $this->resource->subject->isLectureComponent(),
        ];
    }
}
