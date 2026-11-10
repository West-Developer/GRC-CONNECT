<?php

namespace App\Http\Resources\Api\V1;

use App\Models\SectionChangeRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read SectionChangeRequest $resource
 */
final class SectionChangeRequestResource extends JsonResource
{
    private const FIELD_LABELS = [
        'schedule_days' => 'Days',
        'starts_at_time' => 'Start time',
        'ends_at_time' => 'End time',
        'room' => 'Room',
        'modality' => 'Modality',
        'capacity' => 'Capacity',
        'viability_threshold' => 'Viability threshold',
    ];

    /**
     * Exact key set. `changes` pairs what the section had when the request was
     * made with what is being asked for, ready to show as old versus new.
     *
     * @return array{
     *     type: string,
     *     id: int,
     *     section_id: int,
     *     section_code: string,
     *     subject_code: string,
     *     subject_title: string,
     *     academic_term_id: int,
     *     status: string,
     *     status_label: string,
     *     reason: string,
     *     requested_by_name: string,
     *     changes: list<array{field: string, label: string, old: mixed, new: mixed}>,
     *     decided_by_name: ?string,
     *     decided_at: ?string,
     *     decision_reason: ?string,
     *     created_at: ?string
     * }
     */
    public function toArray(Request $request): array
    {
        $changeRequest = $this->resource;
        $changes = [];
        foreach ($changeRequest->new_values as $field => $new) {
            $changes[] = [
                'field' => $field,
                'label' => self::FIELD_LABELS[$field] ?? $field,
                'old' => $changeRequest->old_values[$field] ?? null,
                'new' => $new,
            ];
        }

        return [
            'type' => 'section_change_request',
            'id' => $changeRequest->id,
            'section_id' => $changeRequest->section_id,
            'section_code' => $changeRequest->section->section_code,
            'subject_code' => $changeRequest->section->subject->code,
            'subject_title' => $changeRequest->section->subject->title,
            'academic_term_id' => $changeRequest->section->academic_term_id,
            'status' => $changeRequest->status->value,
            'status_label' => $changeRequest->status->label(),
            'reason' => $changeRequest->reason,
            'requested_by_name' => $changeRequest->requester->name,
            'changes' => $changes,
            'decided_by_name' => $changeRequest->decider?->name,
            'decided_at' => $changeRequest->decided_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'decision_reason' => $changeRequest->decision_reason,
            'created_at' => $changeRequest->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
