<?php

namespace App\Http\Requests\Api\V1\QueueTicket;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `student_number` is required only when Accounting Staff issues a ticket
 * on a student's behalf at the front desk; a Student claiming their own
 * ticket sends an empty body. `EnrollmentPolicy::claimQueueTicket` and
 * `QueueTicketController::resolveEnrollment` are what actually enforce
 * which caller may omit it — this request only validates shape.
 */
final class StoreQueueTicketRequest extends FormRequest
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
            'student_number' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
