<?php

namespace App\Http\Requests\Api\V1\WithdrawalRequest;

use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\WithdrawalStatus;
use App\Models\Enrollment;
use App\Models\WithdrawalRequest as WithdrawalRequestModel;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PRD §4.2 rule 7 requires a reason for withdrawal — the same stated rule
 * that makes `withdrawal_requests.reason` `NOT NULL` in the schema.
 * Withdrawal is only accepted from an `enrolled` enrollment (§4.2's
 * lifecycle has no earlier point a "withdrawal" — as opposed to a
 * Registrar `void` — makes sense). Since `withdrawal_requests` has no
 * unique constraint on `enrollment_id`, this re-checks "no request already
 * pending" here rather than relying on the database.
 */
final class StoreWithdrawalRequestRequest extends FormRequest
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
            'reason' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Enrollment $enrollment */
            $enrollment = $this->route('enrollment');

            if ($enrollment->status !== EnrollmentStatus::Enrolled) {
                $validator->errors()->add(
                    'enrollment',
                    'Withdrawal can only be requested for an enrollment that is currently '.
                    "'enrolled'; it is currently '{$enrollment->status->value}'.",
                );

                return;
            }

            $alreadyPending = WithdrawalRequestModel::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('status', WithdrawalStatus::Pending)
                ->exists();

            if ($alreadyPending) {
                $validator->errors()->add(
                    'enrollment',
                    'A withdrawal request is already pending for this enrollment.',
                );
            }
        });
    }
}
