<?php

namespace App\Http\Requests\Api\V1\QueueTicket;

use App\Domain\Enrollment\QueueTicketStatus;
use App\Models\QueueTicket;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One `action` field drives every transition (FR-FIN-006). §17 leaves
 * reset cadence, priority eligibility, and "how many tickets may be
 * `serving` at once" unconfirmed, so this class enforces only the
 * three-step order (`waiting` → `serving` → `served`, `skip` from either
 * `waiting` or `serving`) and nothing about which or how many tickets an
 * Accounting Staff member may advance. `mark_priority` is not a status
 * transition — see `App\Actions\Enrollment\TransitionQueueTicket`. Neither is
 * `announce`, a repeatable ping that only a `serving` ticket accepts.
 */
final class UpdateQueueTicketRequest extends FormRequest
{
    /**
     * @var array<string, list<QueueTicketStatus>>
     */
    private const REQUIRED_CURRENT_STATUS = [
        'serve' => [QueueTicketStatus::Waiting],
        'complete' => [QueueTicketStatus::Serving],
        'skip' => [QueueTicketStatus::Waiting, QueueTicketStatus::Serving],
        'mark_priority' => [QueueTicketStatus::Waiting],
        'announce' => [QueueTicketStatus::Serving],
    ];

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
            'action' => ['required', 'string', Rule::in(array_keys(self::REQUIRED_CURRENT_STATUS))],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $action = $this->input('action');

            if (! is_string($action) || ! isset(self::REQUIRED_CURRENT_STATUS[$action])) {
                return;
            }

            /** @var QueueTicket $ticket */
            $ticket = $this->route('queueTicket');
            $requiredStatuses = self::REQUIRED_CURRENT_STATUS[$action];

            if (! in_array($ticket->status, $requiredStatuses, true)) {
                $expected = implode("' or '", array_map(
                    fn (QueueTicketStatus $status): string => $status->value,
                    $requiredStatuses,
                ));
                $validator->errors()->add(
                    'action',
                    "This action requires the ticket to currently be '{$expected}'; ".
                    "it is currently '{$ticket->status->value}'.",
                );
            }
        });
    }
}
