<?php

namespace App\Http\Requests\Api\V1\EnrollmentChangeRequest;

use App\Domain\Enrollment\AddDropWindowResolver;
use App\Domain\Enrollment\EnrollmentChangeRequestStatus;
use App\Domain\Enrollment\EnrollmentChangeRequestType;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Scheduling\SectionConflictDetector;
use App\Domain\Scheduling\SectionStatus;
use App\Models\CurriculumSubject;
use App\Models\Enrollment;
use App\Models\EnrollmentChangeRequest as EnrollmentChangeRequestModel;
use App\Models\EnrollmentSubject;
use App\Models\Section;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `reason` is required per the explicit user requirement that every
 * add/drop/change-section request states why. Every other rule here is a
 * fast, friendly pre-check — `TransitionEnrollmentChangeRequest::apply()`
 * re-checks target-section seat capacity authoritatively, under a row lock,
 * at approval time, the same "Form Request pre-check, transaction lock is
 * authoritative for capacity" split `StoreEnrollmentRequest`/
 * `SubmitEnrollment` use.
 *
 * Deliberately scoped narrower than `StoreEnrollmentRequest`: this does not
 * re-run `BuildEligibleSubjectPool`'s prerequisite checks for the target
 * subject — only that the target subject is in the student's own curriculum
 * (Doc 12, same code + units counts, as in the pool), and that the target
 * section exists, is published, is in the same term, and does not
 * schedule-conflict with the student's other currently-held sections. Full
 * eligibility re-verification for an ad-hoc add/drop is out of scope.
 */
final class StoreEnrollmentChangeRequestRequest extends FormRequest
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
            'type' => ['required', 'string', Rule::in(array_map(
                fn (EnrollmentChangeRequestType $type): string => $type->value,
                EnrollmentChangeRequestType::cases(),
            ))],
            'from_section_id' => ['nullable', 'integer', 'exists:sections,id'],
            'to_section_id' => ['nullable', 'integer', 'exists:sections,id'],
            'reason' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = EnrollmentChangeRequestType::tryFrom((string) $this->input('type'));

            if ($type === null) {
                return;
            }

            /** @var Enrollment $enrollment */
            $enrollment = $this->route('enrollment');

            $this->validateSectionShape($validator, $type);
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($enrollment->status !== EnrollmentStatus::Enrolled) {
                $validator->errors()->add(
                    'enrollment',
                    'An enrollment change can only be requested for an enrollment that is currently '.
                    "'enrolled'; it is currently '{$enrollment->status->value}'.",
                );

                return;
            }

            $term = $enrollment->academicTerm;
            $availability = AddDropWindowResolver::resolve(
                $term->status,
                $term->enrollment_closes_at,
                $term->add_drop_deadline_at,
                now()->toImmutable(),
                true, // student is already enrolled; skip EnrollmentStillOpen check
                $term->add_drop_opens_at,
            );

            if (! $availability->isOpen) {
                $validator->errors()->add('enrollment', $availability->reason->message());

                return;
            }

            $fromSectionId = $this->input('from_section_id');
            $toSectionId = $this->input('to_section_id');
            $fromSection = $fromSectionId !== null ? Section::query()->where('id', $fromSectionId)->first() : null;
            $toSection = $toSectionId !== null ? Section::query()->where('id', $toSectionId)->first() : null;

            if ($type->requiresFromSection()) {
                $this->validateFromSection($validator, $enrollment, $fromSection);
            }
            if ($type->requiresToSection()) {
                $this->validateToSection($validator, $enrollment, $type, $fromSection, $toSection);
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $subjectId = ($toSection ?? $fromSection)?->subject_id;
            if ($subjectId !== null) {
                $this->rejectDuplicatePendingRequest(
                    $validator,
                    $enrollment,
                    $subjectId,
                    $toSection !== null ? 'to_section_id' : 'from_section_id',
                );
            }
        });
    }

    private function validateSectionShape(Validator $validator, EnrollmentChangeRequestType $type): void
    {
        if ($type->requiresFromSection() && $this->input('from_section_id') === null) {
            $validator->errors()->add('from_section_id', 'A current section is required for this request type.');
        }
        if (! $type->requiresFromSection() && $this->input('from_section_id') !== null) {
            $validator->errors()->add('from_section_id', 'A current section is not accepted for this request type.');
        }
        if ($type->requiresToSection() && $this->input('to_section_id') === null) {
            $validator->errors()->add('to_section_id', 'A target section is required for this request type.');
        }
        if (! $type->requiresToSection() && $this->input('to_section_id') !== null) {
            $validator->errors()->add('to_section_id', 'A target section is not accepted for this request type.');
        }
    }

    private function validateFromSection(
        Validator $validator,
        Enrollment $enrollment,
        ?Section $fromSection,
    ): void {
        if ($fromSection === null) {
            $validator->errors()->add('from_section_id', 'The selected current section was not found.');

            return;
        }

        $held = EnrollmentSubject::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('section_id', $fromSection->id)
            ->where('status', '!=', EnrollmentSubjectStatus::Dropped->value)
            ->exists();

        if (! $held) {
            $validator->errors()->add(
                'from_section_id',
                'You cannot drop or change a section you are not actively enrolled in.',
            );
        }
    }

    private function validateToSection(
        Validator $validator,
        Enrollment $enrollment,
        EnrollmentChangeRequestType $type,
        ?Section $fromSection,
        ?Section $toSection,
    ): void {
        if ($toSection === null) {
            $validator->errors()->add('to_section_id', 'The selected target section was not found.');

            return;
        }

        if ($toSection->academic_term_id !== $enrollment->academic_term_id) {
            $validator->errors()->add('to_section_id', 'The target section does not belong to this enrollment term.');

            return;
        }

        if ($toSection->status !== SectionStatus::Published) {
            $validator->errors()->add('to_section_id', 'The target section is not currently open for selection.');

            return;
        }

        if ($fromSection !== null && $toSection->id === $fromSection->id) {
            $validator->errors()->add('to_section_id', 'The target section must be different from your current section.');

            return;
        }

        // A subject can only be added (or swapped in) from the student's OWN
        // curriculum (Doc 12): the picker already offers nothing else, and this
        // keeps a hand-built request from reaching another curriculum's subject.
        // A different section of the subject already held is fine by definition.
        $isSameSubjectAsHeld = $type === EnrollmentChangeRequestType::ChangeSection
            && $fromSection !== null
            && $toSection->subject_id === $fromSection->subject_id;

        if (! $isSameSubjectAsHeld && ! $this->isInStudentsCurriculum($enrollment, $toSection)) {
            $validator->errors()->add('to_section_id', 'That subject is not part of your curriculum.');

            return;
        }

        if ($type === EnrollmentChangeRequestType::ChangeSection && $fromSection !== null
            && $toSection->subject_id !== $fromSection->subject_id) {
            $alreadyHeld = EnrollmentSubject::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('status', '!=', EnrollmentSubjectStatus::Dropped->value)
                ->where('section_id', '!=', $fromSection->id)
                ->whereHas('section', fn ($sectionQuery) => $sectionQuery->where('subject_id', $toSection->subject_id))
                ->exists();

            if ($alreadyHeld) {
                $validator->errors()->add('to_section_id', 'This subject is already part of your enrollment.');

                return;
            }
        }

        if ($toSection->remainingSeats() < 1) {
            $validator->errors()->add('to_section_id', 'The target section has no open seats.');

            return;
        }

        $otherActiveSections = EnrollmentSubject::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('status', '!=', EnrollmentSubjectStatus::Dropped->value)
            ->when($fromSection !== null, fn ($query) => $query->where('section_id', '!=', $fromSection?->id))
            ->with('section')
            ->get()
            ->map(fn (EnrollmentSubject $subject): array => [
                'schedule_days' => $subject->section->schedule_days,
                'starts_at_time' => $subject->section->starts_at_time,
                'ends_at_time' => $subject->section->ends_at_time,
            ]);
        $otherActiveSections = array_values($otherActiveSections->all());

        $detector = app(SectionConflictDetector::class);
        $proposed = [
            'schedule_days' => $toSection->schedule_days,
            'starts_at_time' => $toSection->starts_at_time,
            'ends_at_time' => $toSection->ends_at_time,
        ];

        if ($detector->hasConflict($proposed, $otherActiveSections)) {
            $validator->errors()->add('to_section_id', 'The target section conflicts with a section already in your enrollment.');
        }

        if ($type === EnrollmentChangeRequestType::Add) {
            $alreadyHeld = EnrollmentSubject::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('status', '!=', EnrollmentSubjectStatus::Dropped->value)
                ->whereHas('section', fn ($sectionQuery) => $sectionQuery->where('subject_id', $toSection->subject_id))
                ->exists();

            if ($alreadyHeld) {
                $validator->errors()->add('to_section_id', 'This subject is already part of your enrollment.');
            }
        }
    }

    /**
     * Same equivalence `BuildEligibleSubjectPool` uses for cross-department
     * sections: a subject row with the same code and units as a curriculum
     * placement counts as that placement (GE subjects exist once per college).
     */
    private function isInStudentsCurriculum(Enrollment $enrollment, Section $toSection): bool
    {
        $subject = $toSection->subject;

        return CurriculumSubject::query()
            ->where('curriculum_id', $enrollment->student->curriculum_id)
            ->whereHas('subject', fn ($query) => $query
                ->where('code', $subject->code)
                ->where('units', $subject->units))
            ->exists();
    }

    private function rejectDuplicatePendingRequest(Validator $validator, Enrollment $enrollment, int $subjectId, string $errorField): void
    {
        $alreadyPending = EnrollmentChangeRequestModel::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('subject_id', $subjectId)
            ->where('status', EnrollmentChangeRequestStatus::Pending->value)
            ->exists();

        if ($alreadyPending) {
            $validator->errors()->add(
                $errorField,
                'A request for this subject is already pending.',
            );
        }
    }
}
