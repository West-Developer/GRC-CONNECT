<?php

namespace App\Http\Requests\Api\V1\Enrollment;

use App\Domain\Enrollment\EnrollmentStatus;
use App\Models\Enrollment;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One `action` field drives all three Registrar Head decisions; which role
 * may perform it is a Policy concern (`EnrollmentPolicy`), not this
 * request's — this class validates only that the action is well-formed and
 * legal *given the enrollment's current status*, and that a reason is
 * present exactly when FR-FIN-002 requires one (reject or void).
 */
final class UpdateEnrollmentRequest extends FormRequest
{
    /**
     * The statuses each action may start from; the same table as
     * `TransitionEnrollment`, checked here so a wrong-state request is a 422
     * before anything is authorized or locked.
     *
     * @var array<string, list<EnrollmentStatus>>
     */
    private const REQUIRED_CURRENT_STATUSES = [
        'program_head_approve' => [EnrollmentStatus::PendingProgramHeadApproval],
        'program_head_reject' => [EnrollmentStatus::PendingProgramHeadApproval],
        'registrar_approve' => [EnrollmentStatus::PendingRegistrarApproval],
        'registrar_reject' => [
            EnrollmentStatus::PendingRegistrarApproval,
            EnrollmentStatus::PendingPayment,
        ],
        'void' => [
            EnrollmentStatus::PendingProgramHeadApproval,
            EnrollmentStatus::PendingStudentReview,
            EnrollmentStatus::AwaitingFirstPayment,
            EnrollmentStatus::PendingRegistrarApproval,
            EnrollmentStatus::PendingPayment,
        ],
        'student_cancel' => [
            EnrollmentStatus::PendingProgramHeadApproval,
            EnrollmentStatus::PendingStudentReview,
            EnrollmentStatus::AwaitingFirstPayment,
            EnrollmentStatus::PendingRegistrarApproval,
        ],
        // The student's answer to the Program Chair's changes (ADR 0040).
        'student_accept_revision' => [EnrollmentStatus::PendingStudentReview],
        'student_decline_revision' => [EnrollmentStatus::PendingStudentReview],
    ];

    private const REASON_REQUIRED_ACTIONS = ['program_head_reject', 'registrar_reject', 'void', 'student_cancel', 'student_decline_revision'];

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
            'action' => ['required', 'string', Rule::in(array_keys(self::REQUIRED_CURRENT_STATUSES))],
            'reason' => [
                Rule::requiredIf(fn (): bool => in_array($this->input('action'), self::REASON_REQUIRED_ACTIONS, true)),
                'nullable',
                'string',
            ],
            'overload_acknowledged' => ['sometimes', 'boolean'],
            // Only meaningful for the two Program Head decisions: an optional note
            // for the student (e.g. why a subject was swapped, or what to take
            // instead). Silently ignored by `TransitionEnrollment` for every other
            // action rather than rejected here, matching `overload_acknowledged`.
            'program_head_comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Only meaningful for `void`: the Registrar is acting on the student's request.
            'requested_by_student' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $action = $this->input('action');

            if (! is_string($action) || ! isset(self::REQUIRED_CURRENT_STATUSES[$action])) {
                return;
            }

            /** @var Enrollment $enrollment */
            $enrollment = $this->route('enrollment');
            $requiredStatuses = self::REQUIRED_CURRENT_STATUSES[$action];

            if (! in_array($enrollment->status, $requiredStatuses, true)) {
                $expected = implode("' or '", array_map(fn (EnrollmentStatus $status): string => $status->value, $requiredStatuses));
                $validator->errors()->add(
                    'action',
                    "This action requires the enrollment to currently be '{$expected}'; ".
                    "it is currently '{$enrollment->status->value}'.",
                );

                return;
            }

            // FR-ENR-004: an enrollment SubmitEnrollment flagged as needing
            // overload approval cannot be approved silently. The Program Head
            // acknowledges it first; the Registrar only has to acknowledge an
            // enrollment the Program Head never saw (one that predates the
            // Program Head stage).
            $needsAcknowledgement = $action === 'program_head_approve'
                || ($action === 'registrar_approve' && $enrollment->program_head_decided_at === null);

            if ($needsAcknowledgement && $enrollment->requires_overload_approval && $this->boolean('overload_acknowledged') !== true) {
                $validator->errors()->add(
                    'overload_acknowledged',
                    'This enrollment exceeds the regular unit load and requires explicit overload acknowledgement before it can be approved.',
                );
            }
        });
    }
}
