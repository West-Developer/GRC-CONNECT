<?php

namespace App\Http\Requests\Api\V1\ScheduleProposal;

use App\Domain\Scheduling\ScheduleProposalTransitionRules;
use App\Models\ScheduleProposal;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One `action` field drives all six transitions; which role may perform it
 * is a Policy concern (ScheduleProposalPolicy), not this request's — this
 * class validates only that the action is well-formed and legal *given the
 * proposal's current status*, and that a reason is present exactly when the
 * PRD requires one (returning to draft).
 */
final class UpdateScheduleProposalRequest extends FormRequest
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
            'action' => ['required', 'string', Rule::in(ScheduleProposalTransitionRules::actions())],
            'decision_reason' => [
                Rule::requiredIf(fn (): bool => is_string($this->input('action')) && ScheduleProposalTransitionRules::isReturn($this->input('action'))),
                'nullable',
                'string',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $action = $this->input('action');

            if (! is_string($action) || ! in_array($action, ScheduleProposalTransitionRules::actions(), true)) {
                return;
            }

            /** @var ScheduleProposal $proposal */
            $proposal = $this->route('scheduleProposal');
            $requiredStatus = ScheduleProposalTransitionRules::requiredStatus($action);

            if ($proposal->status !== $requiredStatus) {
                $validator->errors()->add(
                    'action',
                    "This action requires the proposal to currently be '{$requiredStatus->value}'; ".
                    "it is currently '{$proposal->status->value}'.",
                );
            }
        });
    }
}
