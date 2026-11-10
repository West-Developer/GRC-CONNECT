<?php

namespace App\Http\Requests\Api\V1\WithdrawalRequest;

use App\Domain\Enrollment\WithdrawalStatus;
use App\Models\WithdrawalRequest as WithdrawalRequestModel;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One `action` field drives both Registrar Staff decisions. `reason` here
 * is the processor's own justification for a rejection (FR-FIN-002) — a
 * separate concept from `withdrawal_requests.reason`, the student's
 * original stated reason at creation, which this route never touches.
 * Recorded only in the audit row, since `withdrawal_requests` has no
 * column of its own for it (mirrors `UpdateEnrollmentRequest`).
 */
final class UpdateWithdrawalRequestRequest extends FormRequest
{
    private const REQUIRED_CURRENT_STATUS = WithdrawalStatus::Pending;

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
            /** @var WithdrawalRequestModel $withdrawalRequest */
            $withdrawalRequest = $this->route('withdrawalRequest');

            if ($withdrawalRequest->status !== self::REQUIRED_CURRENT_STATUS) {
                $validator->errors()->add(
                    'action',
                    'This action requires the withdrawal request to currently be '.
                    "'".self::REQUIRED_CURRENT_STATUS->value."'; it is currently ".
                    "'{$withdrawalRequest->status->value}'.",
                );
            }
        });
    }
}
