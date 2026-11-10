<?php

namespace App\Http\Requests\Api\V1\TransfereeCredit;

use App\Domain\Identity\UserRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A credit request (ADR 0026). A Student asks for one for themselves: they
 * give the school, subject title, units, grade (optional), school year and
 * semester, may leave the subject code blank, and can never name a student or
 * a GRC subject (`CreateTransfereeCredit` pins the student to them, and
 * mapping is the Program Chair's decision). A Program Chair records one for a
 * student in their college and may map it. `source_grade` stays a free string
 * with no equivalence rule encoded, since PRD §17 leaves cross-institution
 * grade equivalence unresolved (see `TransfereeCreditStatus`'s docblock).
 */
final class StoreTransfereeCreditRequest extends FormRequest
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
        $isStudent = $this->user()?->role === UserRole::Student;

        return [
            'student_id' => ['sometimes', 'nullable', 'integer', 'exists:student_profiles,id'],
            'source_institution' => ['required', 'string', 'max:255'],
            'source_subject_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'source_subject_title' => ['required', 'string', 'max:255'],
            'source_grade' => ['sometimes', 'nullable', 'string', 'max:20'],
            'credited_units' => ['required', 'decimal:0,1', 'gt:0', 'max:99.9'],
            'source_school_year' => $isStudent
                ? ['required', 'string', 'max:32']
                : ['sometimes', 'nullable', 'string', 'max:32'],
            'source_semester' => $isStudent
                ? ['required', 'string', 'max:32']
                : ['sometimes', 'nullable', 'string', 'max:32'],
            'subject_id' => $isStudent
                ? ['prohibited']
                : ['sometimes', 'nullable', 'integer', 'exists:subjects,id'],
        ];
    }
}
