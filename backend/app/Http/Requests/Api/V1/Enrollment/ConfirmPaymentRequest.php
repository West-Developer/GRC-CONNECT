<?php

namespace App\Http\Requests\Api\V1\Enrollment;

use App\Actions\Enrollment\PromissoryNoteRule;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\Payment;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payment confirmation remains optional in shape, but an explicitly supplied
 * enrollment payment must be at least PHP 1,000.00. This is the enrollment
 * rule agreed for the Cashier workflow; it does not apply to later account
 * balance payments. Amounts must never go through binary floating point, so
 * validation is constrained to the column's exact `decimal(10,2)` capacity.
 *
 * The current-status check mirrors `ConfirmPayment`'s own idempotency-first
 * ordering: a repeat request against an enrollment that already has a
 * `Payment` row is always allowed through, even though the enrollment's
 * status has since moved on to `enrolled`.
 *
 * A `payment_type` of `full` carries the `quoted_amount` and no `amount`; `installment` carries
 * an `amount` (ADR 0046).
 */
final class ConfirmPaymentRequest extends FormRequest
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
            'external_reference' => ['sometimes', 'nullable', 'string'],
            'amount' => ['sometimes', 'nullable', 'numeric', 'min:1000', 'max:99999999.99'],
            'promissory_note_on_file' => ['sometimes', 'boolean'],
            // ADR 0046. Absent keeps today's behavior exactly (no discount).
            'payment_type' => ['sometimes', 'string', Rule::in(['full', 'installment'])],
            // A plain decimal with at most two places: "1e3" or "945.005" would break (or be truncated by) bcmath.
            'quoted_amount' => ['sometimes', 'nullable', 'numeric', 'regex:/^\d+(\.\d{1,2})?$/', 'min:0', 'max:99999999.99'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Enrollment $enrollment */
            $enrollment = $this->route('enrollment');

            $alreadyConfirmed = Payment::query()->where('enrollment_id', $enrollment->id)->exists();

            if ($alreadyConfirmed) {
                return;
            }

            if ($enrollment->status !== EnrollmentStatus::PendingPayment) {
                $validator->errors()->add(
                    'enrollment',
                    "Payment can only be confirmed for an enrollment that is currently 'pending_payment'; ".
                    "it is currently '{$enrollment->status->value}'.",
                );
            }

            $amount = $this->input('amount');
            $promissoryNoteOnFile = (bool) $this->input('promissory_note_on_file', false);
            $totalDue = $enrollment->assessment?->total_amount;
            $paymentType = $this->input('payment_type');

            if ($paymentType === 'full') {
                if ($amount !== null) {
                    $validator->errors()->add('amount', 'Do not send an amount with a full payment; it collects the quoted net.');
                }

                if ($this->input('quoted_amount') === null) {
                    $validator->errors()->add('quoted_amount', 'Send the net amount the Cashier was shown.');
                }
            }

            if ($paymentType === 'installment' && $amount === null) {
                $validator->errors()->add('amount', 'Enter the amount received for an installment.');
            }

            // A promissory note is only for a student who carries a previous bill (owner, 2026-10-13); the
            // rule is `PromissoryNoteRule`, shared with `ConfirmPayment`. Skipped when the amount itself is
            // invalid, which is reported on its own.
            if (! $promissoryNoteOnFile
                && ! $validator->errors()->has('amount')
                && app(PromissoryNoteRule::class)->isRequired($enrollment, $amount, $totalDue)) {
                $validator->errors()->add(
                    'promissory_note_on_file',
                    'A promissory note on file is required for partial payments.',
                );
            }
        });
    }
}
