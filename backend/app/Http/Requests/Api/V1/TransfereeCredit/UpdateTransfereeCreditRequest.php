<?php

namespace App\Http\Requests\Api\V1\TransfereeCredit;

use App\Domain\Academic\TransfereeCreditStatus;
use App\Domain\Identity\UserRole;
use App\Models\TransfereeCredit;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One route serves every step after a credit exists, told apart by `action`
 * (ADR 0026; see `UpdateTransfereeCredit`):
 *
 *   - none, by the Student: the student corrects their own `draft`;
 *   - none, by the Program Chair: corrects or maps a `pending` credit;
 *   - `endorse`: the Program Chair endorses a `pending` credit (the request
 *     may carry `subject_id` / `credited_units` to map it in the same step,
 *     and there must be a mapped subject by the end);
 *   - `decline`: the Program Chair rejects a `pending` request, with a reason;
 *   - `approve` / `reject` / `return`: the Registrar Head decides an `endorsed`
 *     credit; `reject` and `return` need a reason, and `return` sends the
 *     credit back to the Program Chair as `pending`.
 *
 * A reason is recorded only in the audit row, since `transferee_credits` has
 * no column of its own for it (mirrors `UpdateWithdrawalRequestRequest`).
 */
final class UpdateTransfereeCreditRequest extends FormRequest
{
    private const PROHIBITS_CONTENT = 'prohibited_if:action,approve,reject,decline,return';

    private const REASON_REQUIRED_ACTIONS = ['decline', 'reject', 'return'];

    /** @var list<string> Actions that need the credit to be `endorsed`. */
    private const NEEDS_ENDORSED = ['approve', 'reject', 'return'];

    /**
     * Authorization comes first, so a role that may not take this step gets a
     * 403 rather than a validation message about the credit's status: the
     * Registrar Head decides (`approve`, `reject`, `return`); a Student edits
     * only their own draft; the Program Chair of the student's college does
     * everything else (`TransfereeCreditPolicy`).
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $credit = $this->route('transfereeCredit');

        if ($user === null || ! $credit instanceof TransfereeCredit) {
            return false;
        }

        $action = $this->input('action');

        if (is_string($action) && in_array($action, self::NEEDS_ENDORSED, true)) {
            return $user->can('decide', TransfereeCredit::class);
        }

        return $user->role === UserRole::Student
            ? $user->can('editDraft', $credit)
            : $user->can('update', $credit);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isStudent = $this->user()?->role === UserRole::Student;

        return [
            // A Student only corrects the content of their own draft: no workflow step, no mapping.
            'action' => $isStudent
                ? ['prohibited']
                : ['sometimes', 'string', Rule::in(['endorse', 'decline', 'approve', 'reject', 'return'])],
            'reason' => [
                Rule::requiredIf(fn (): bool => in_array($this->input('action'), self::REASON_REQUIRED_ACTIONS, true)),
                'nullable',
                'string',
                'max:1000',
            ],
            'source_institution' => [self::PROHIBITS_CONTENT, 'sometimes', 'string', 'max:255'],
            'source_subject_code' => [self::PROHIBITS_CONTENT, 'sometimes', 'nullable', 'string', 'max:50'],
            'source_subject_title' => [self::PROHIBITS_CONTENT, 'sometimes', 'string', 'max:255'],
            'source_grade' => [self::PROHIBITS_CONTENT, 'sometimes', 'nullable', 'string', 'max:20'],
            'source_school_year' => [self::PROHIBITS_CONTENT, 'sometimes', 'nullable', 'string', 'max:32'],
            'source_semester' => [self::PROHIBITS_CONTENT, 'sometimes', 'nullable', 'string', 'max:32'],
            'credited_units' => [self::PROHIBITS_CONTENT, 'sometimes', 'decimal:0,1', 'gt:0', 'max:99.9'],
            'subject_id' => $isStudent
                ? ['prohibited']
                : [self::PROHIBITS_CONTENT, 'sometimes', 'nullable', 'integer', 'exists:subjects,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var TransfereeCredit $credit */
            $credit = $this->route('transfereeCredit');
            $action = $this->input('action');

            $required = match (true) {
                is_string($action) && in_array($action, self::NEEDS_ENDORSED, true) => TransfereeCreditStatus::Endorsed,
                $this->user()?->role === UserRole::Student => TransfereeCreditStatus::Draft,
                default => TransfereeCreditStatus::Pending,
            };

            if ($credit->status !== $required) {
                if (is_string($action)) {
                    $validator->errors()->add(
                        'action',
                        "This action requires the transferee credit to currently be '{$required->value}'; ".
                        "it is currently '{$credit->status->value}'.",
                    );
                } else {
                    $validator->errors()->add(
                        'source_institution',
                        "This transferee credit is '{$credit->status->value}' and can no longer be edited directly.",
                    );
                }

                return;
            }

            if ($action === 'endorse') {
                $mapped = $this->has('subject_id') ? $this->input('subject_id') : $credit->subject_id;

                if ($mapped === null) {
                    $validator->errors()->add('subject_id', 'Map this credit to a subject before endorsing it.');
                }
            }
        });
    }
}
