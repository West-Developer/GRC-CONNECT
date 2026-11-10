<?php

namespace App\Http\Requests\Api\V1\Enrollment;

use App\Domain\Identity\UserRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A Program Head's full replacement list of section ids for a pending
 * enrollment (see `ReviseEnrollmentSubjects`). Authorization is a Policy
 * concern (`EnrollmentPolicy::reviseSubjects`, own-college record-level,
 * same as the approve/reject decision); this only validates shape — the
 * Action re-checks seats, term membership, and the overload ceiling under a
 * lock, the same authoritative-server pattern `StoreEnrollmentRequest`
 * already follows for the student's own submission.
 */
final class ReviseEnrollmentSubjectsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'section_ids' => ['required', 'array', 'min:1'],
            'section_ids.*' => ['integer', 'distinct', 'exists:sections,id'],
            // Why the subjects were changed. The student reads this before accepting (ADR 0040).
            'note' => ['required', 'string', 'max:2000'],
            // Required in effect (checked by the Action) only when the new load needs overload approval.
            'overload_acknowledged' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => $this->user()?->role === UserRole::Student
                ? 'Explain why you are proposing these subjects.'
                : 'Tell the student why you are changing their subjects.',
        ];
    }

    /**
     * @return list<int>
     */
    public function resolvedSectionIds(): array
    {
        /** @var list<int> $ids */
        $ids = $this->validated('section_ids');

        return $ids;
    }
}
