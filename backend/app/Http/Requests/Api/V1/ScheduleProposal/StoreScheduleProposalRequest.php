<?php

namespace App\Http\Requests\Api\V1\ScheduleProposal;

use App\Domain\Scheduling\ScheduleProposalStatus;
use App\Models\ScheduleProposal;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Submitting a proposal always creates it in `draft` — the request accepts
 * no `status` field. `submitted_by` is forced server-side to the
 * authenticated user, never accepted from the request body.
 */
final class StoreScheduleProposalRequest extends FormRequest
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
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $termId = $this->input('academic_term_id');

            if (! is_numeric($termId)) {
                return;
            }

            $hasActiveProposal = ScheduleProposal::query()
                ->where('academic_term_id', $termId)
                ->when($this->user()?->college !== null, fn ($query) => $query->where('college', $this->user()->college->value))
                ->where('status', '!=', ScheduleProposalStatus::Closed->value)
                ->exists();

            if ($hasActiveProposal) {
                $validator->errors()->add(
                    'academic_term_id',
                    'This term already has an active (non-closed) schedule proposal.',
                );
            }
        });
    }
}
