<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Identity\StudentType;
use App\Models\QueueTicket;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read QueueTicket $resource
 */
final class QueueTicketResource extends JsonResource
{
    /**
     * Exact key set. `student_number` (never email or name) lets Accounting
     * identify whose ticket this is, matching `EnrollmentResource`'s
     * precedent. `enrollment_id` is null for a freshman who took a number at
     * Admission, or a transferee whose credit mapping closed, and has no
     * enrollment yet; `student_id` (the student profile) is always set, and
     * `is_freshman` / `is_transferee` are what let the Cashier see that who is
     * being served pays first and which kind of student it is. `served_by` is deliberately absent — actor
     * identity is never rendered to students, and Accounting identifies its
     * own staff from context, not this response. `created_at` is exposed so
     * the frontend can reproduce this same `COALESCE(requeued_at, created_at)`
     * ordering locally rather than guessing at it independently.
     *
     * @return array{
     *     type: string,
     *     id: int,
     *     enrollment_id: ?int,
     *     student_id: int,
     *     student_number: string,
     *     is_freshman: bool,
     *     is_transferee: bool,
     *     ticket_number: string,
     *     queue_date: string,
     *     status: string,
     *     status_label: string,
     *     priority: string,
     *     priority_label: string,
     *     created_at: ?string,
     *     served_at: ?string,
     *     requeued_at: ?string
     * }
     */
    public function toArray(Request $request): array
    {
        // A ticket always has one owner (a CHECK on the table), so `student()` is never null.
        $student = $this->resource->student();
        assert($student !== null);

        return [
            'type' => 'queue_ticket',
            'id' => $this->resource->id,
            'enrollment_id' => $this->resource->enrollment_id,
            'student_id' => $student->id,
            'student_number' => $student->student_number,
            'is_freshman' => $student->student_type === StudentType::Freshman,
            'is_transferee' => $student->student_type === StudentType::Transferee,
            'ticket_number' => $this->resource->ticket_number,
            'queue_date' => $this->resource->queue_date->toDateString(),
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
            'priority' => $this->resource->priority->value,
            'priority_label' => $this->resource->priority->label(),
            'created_at' => self::formatTimestamp($this->resource->created_at),
            'served_at' => self::formatTimestamp($this->resource->served_at),
            'requeued_at' => self::formatTimestamp($this->resource->requeued_at),
        ];
    }

    private static function formatTimestamp(?CarbonInterface $timestamp): ?string
    {
        return $timestamp?->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
