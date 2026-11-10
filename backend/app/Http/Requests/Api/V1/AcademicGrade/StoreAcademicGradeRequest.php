<?php

namespace App\Http\Requests\Api\V1\AcademicGrade;

use App\Domain\Academic\CompletionOnlySubjectRule;
use App\Domain\Academic\GradeMark;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Models\AcademicGrade;
use App\Models\EnrollmentSubject;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Faculty-only (DFD 3.1): every grade this endpoint creates is tied to one
 * of the encoding professor's own sections, so `section_id` is required here
 * even though the column itself is nullable (that nullability exists for a
 * future transferee-credit path this slice does not implement). Re-checks
 * `section.professor_id === auth()->id()` as defense in depth alongside
 * `AcademicGradePolicy::create`, the same shape `StoreEnrollmentRequest`
 * uses for its own server-side re-validation.
 */
final class StoreAcademicGradeRequest extends FormRequest
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
            'student_id' => ['required', 'integer', 'exists:student_profiles,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'section_id' => ['required', 'integer', 'exists:sections,id'],
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'mark' => ['sometimes', 'nullable', Rule::enum(GradeMark::class)->except(GradeMark::Withdrawn)],
            'remarks' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $sectionId = $this->input('section_id');

            if (! is_numeric($sectionId)) {
                return;
            }

            $section = Section::query()->find((int) $sectionId);
            $user = $this->user();

            if ($section !== null && $user instanceof User && $section->professor_id !== $user->id) {
                $validator->errors()->add('section_id', 'This section is not assigned to you.');

                return;
            }

            $studentId = $this->input('student_id');
            $subjectId = $this->input('subject_id');
            $academicTermId = $this->input('academic_term_id');

            if (! is_numeric($studentId) || ! is_numeric($subjectId) || ! is_numeric($academicTermId)) {
                return;
            }

            if ($section !== null && $section->subject_id !== (int) $subjectId) {
                $validator->errors()->add('subject_id', 'The subject does not match the selected section.');
            }

            if ($section !== null && $section->academic_term_id !== (int) $academicTermId) {
                $validator->errors()->add('academic_term_id', 'The academic term does not match the selected section.');
            }

            if ($section !== null) {
                $isEnrolledInSection = EnrollmentSubject::query()
                    ->where('section_id', $section->id)
                    ->where('status', EnrollmentSubjectStatus::Enrolled->value)
                    ->whereHas('enrollment', function ($query) use ($studentId, $section): void {
                        $query->where('student_id', (int) $studentId)
                            ->where('academic_term_id', $section->academic_term_id)
                            ->where('status', EnrollmentStatus::Enrolled->value);
                    })
                    ->exists();

                if (! $isEnrolledInSection) {
                    $validator->errors()->add('student_id', 'The student is not enrolled in this section.');
                }
            }

            $duplicateExists = AcademicGrade::query()
                ->where('student_id', (int) $studentId)
                ->where('subject_id', (int) $subjectId)
                ->where('academic_term_id', (int) $academicTermId)
                ->exists();

            if ($duplicateExists) {
                $validator->errors()->add(
                    'subject_id',
                    'A grade record already exists for this student, subject, and term.',
                );

                return;
            }

            $this->rejectMarkNotAllowedForSubject($validator, (int) $subjectId);
        });
    }

    private function rejectMarkNotAllowedForSubject(Validator $validator, int $subjectId): void
    {
        $rawMark = $this->input('mark');

        if ($rawMark === null || $rawMark === '') {
            return;
        }

        $mark = GradeMark::tryFrom((string) $rawMark);
        $subject = Subject::query()->find($subjectId);

        if ($mark === null || $subject === null) {
            return;
        }

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
