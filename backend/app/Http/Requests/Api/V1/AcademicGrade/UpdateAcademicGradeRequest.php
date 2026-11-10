<?php

namespace App\Http\Requests\Api\V1\AcademicGrade;

use App\Domain\Academic\CompletionOnlySubjectRule;
use App\Domain\Academic\GradeMark;
use App\Domain\Academic\GradeStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One route serves two different concerns, distinguished by whether
 * `action` is present:
 *
 *   - No `action`: a plain content edit (`mark`/`remarks`) by the
 *     encoding Faculty member, allowed only while the grade is still
 *     `draft` (PRD §4.3 — `GradeStatus::isEditableByEncoder()`).
 *   - `action: submit` (`draft` → `submitted`, Faculty) or
 *     `action: lock` (`submitted` → `locked`, Registrar Head): a pure state
 *     transition, following the same `REQUIRED_CURRENT_STATUS` shape as
 *     `UpdateEnrollmentRequest`/`UpdateScheduleProposalRequest` (ADR 0011) —
 *     content fields are prohibited on these requests so the two concerns
 *     never mix in one call.
 */
final class UpdateAcademicGradeRequest extends FormRequest
{
    /**
     * @var array<string, GradeStatus>
     */
    private const REQUIRED_CURRENT_STATUS_FOR_ACTION = [
        'submit' => GradeStatus::Draft,
        'lock' => GradeStatus::Submitted,
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
            'action' => ['sometimes', 'string', Rule::in(array_keys(self::REQUIRED_CURRENT_STATUS_FOR_ACTION))],
            'mark' => ['prohibited_if:action,submit,lock', 'sometimes', 'nullable', Rule::enum(GradeMark::class)->except(GradeMark::Withdrawn)],
            'remarks' => ['prohibited_if:action,submit,lock', 'sometimes', 'nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var AcademicGrade $grade */
            $grade = $this->route('academicGrade');
            $action = $this->input('action');

            if (is_string($action) && isset(self::REQUIRED_CURRENT_STATUS_FOR_ACTION[$action])) {
                $requiredStatus = self::REQUIRED_CURRENT_STATUS_FOR_ACTION[$action];

                if ($grade->status !== $requiredStatus) {
                    $validator->errors()->add(
                        'action',
                        "This action requires the grade to currently be '{$requiredStatus->value}'; ".
                        "it is currently '{$grade->status->value}'.",
                    );
                }

                return;
            }

            $isIncCompletion = false;
            if (! $grade->status->isEditableByEncoder()) {
                if ($grade->mark === GradeMark::Incomplete) {
                    $currentTerm = AcademicTerm::query()
                        ->where('status', AcademicTermStatus::SemesterOngoing)
                        ->first();
                    $gradeTerm = $grade->academicTerm;
                    if ($currentTerm !== null && $gradeTerm !== null) {
                        $elapsed = $currentTerm->termsElapsedSince($gradeTerm);
                        if ($elapsed <= 3) {
                            $isIncCompletion = true;
                        }
                    }
                }

                if (! $isIncCompletion) {
                    $validator->errors()->add(
                        'mark',
                        "This grade is '{$grade->status->value}' and can no longer be edited directly.",
                    );

                    return;
                }
            }

            $this->rejectMarkNotAllowedForSubject($validator, $grade);
        });
    }

    private function rejectMarkNotAllowedForSubject(Validator $validator, AcademicGrade $grade): void
    {
        $rawMark = $this->input('mark');

        if ($rawMark === null || $rawMark === '') {
            return;
        }

        $mark = GradeMark::tryFrom((string) $rawMark);

        if ($mark === null) {
            return;
        }

        $subject = $grade->subject;

        /** @var list<string> $prefixes */
        $prefixes = (array) config('enrollment.grading.completion_only_code_prefixes', []);
        $allowed = CompletionOnlySubjectRule::allowedMarks($subject->code, $prefixes);

        if (! in_array($mark, $allowed, true)) {
            $message = CompletionOnlySubjectRule::matches($subject->code, $prefixes)
                ? "{$subject->code} is a Leadership subject and is recorded as Complete (C) or Incomplete (INC), not a numeric grade."
                : "{$subject->code} cannot be recorded as Complete/Not Complete.";

            $validator->errors()->add('mark', $message);
        }
    }
}
