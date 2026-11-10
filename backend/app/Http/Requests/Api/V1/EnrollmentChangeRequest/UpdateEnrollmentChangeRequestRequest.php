<?php

namespace App\Http\Requests\Api\V1\EnrollmentChangeRequest;

use App\Domain\Enrollment\EnrollmentChangeRequestStatus;
use App\Models\EnrollmentChangeRequest as EnrollmentChangeRequestModel;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One `action` field drives the Registrar Head's decision. `reason` here is
 * the Registrar's own justification for a rejection — a separate concept
 * from `enrollment_change_requests.reason`, the student's original stated
 * reason at creation, which this route never touches. Mirrors
 * `UpdateWithdrawalRequestRequest`.
 */
final class UpdateEnrollmentChangeRequestRequest extends FormRequest
{
    private const REQUIRED_CURRENT_STATUS = EnrollmentChangeRequestStatus::Pending;

    private const REASON_REQUIRED_ACTIONS = ['reject'];

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
            'action' => ['required', 'string', Rule::in(['approve', 'reject'])],
            'reason' => [
                Rule::requiredIf(fn (): bool => in_array($this->input('action'), self::REASON_REQUIRED_ACTIONS, true)),
                'nullable',
                'string',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var EnrollmentChangeRequestModel $changeRequest */
            $changeRequest = $this->route('enrollmentChangeRequest');

            if ($changeRequest->status !== self::REQUIRED_CURRENT_STATUS) {
                $validator->errors()->add(
                    'action',
                    'This action requires the request to currently be '.
                    "'".self::REQUIRED_CURRENT_STATUS->value."'; it is currently ".
                    "'{$changeRequest->status->value}'.",
                );
            }
        });
    }
}
