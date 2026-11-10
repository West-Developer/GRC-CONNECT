<?php

namespace App\Actions\Identity;

use App\Domain\Identity\AdmissionRequirementCategory;
use App\Models\AdmissionRequirementType;
use App\Models\StudentAdmissionRequirement;
use App\Models\StudentProfile;

/**
 * A student's Admission requirements checklist (stakeholder Doc 14, ADR 0037):
 * the categories that apply to their student type (Freshman or Transferee,
 * plus Additional), each requirement with whether it was handed in.
 *
 * The checklist is a record Admission Staff keep; it does not change
 * `requirements_verified_at`, which stays the explicit confirmation made when
 * the student record was created.
 */
final class BuildAdmissionChecklist
{
    /**
     * @return array<string, mixed>
     */
    public function execute(StudentProfile $student): array
    {
        $categories = AdmissionRequirementCategory::forStudentType($student->student_type);

        $types = AdmissionRequirementType::query()
            ->where('is_active', true)
            ->whereIn('category', array_map(static fn (AdmissionRequirementCategory $category): string => $category->value, $categories))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        /** @var array<int, StudentAdmissionRequirement> $records */
        $records = StudentAdmissionRequirement::query()
            ->where('student_profile_id', $student->id)
            ->get()
            ->keyBy('requirement_type_id')
            ->all();

        $groups = [];
        $required = 0;
        $submitted = 0;

        foreach ($categories as $category) {
            $items = [];
            foreach ($types->where('category', $category) as $type) {
                $record = $records[$type->id] ?? null;
                $isSubmitted = $record instanceof StudentAdmissionRequirement && $record->is_submitted;
                $required++;
                $submitted += $isSubmitted ? 1 : 0;
                $items[] = [
                    'requirement_type_id' => $type->id,
                    'name' => $type->name,
                    'is_system' => $type->is_system,
                    'is_submitted' => $isSubmitted,
                    'submitted_at' => $isSubmitted ? $record->submitted_at?->utc()->format('Y-m-d\TH:i:s\Z') : null,
                ];
            }

            $groups[] = [
                'category' => $category->value,
                'label' => $category->label(),
                'items' => $items,
            ];
        }

        return [
            'student' => [
                'student_profile_id' => $student->id,
                'student_number' => $student->student_number,
                'name' => $student->user->name,
                'student_type' => $student->student_type?->value,
                'student_type_label' => $student->student_type?->label(),
                'admission_status' => $student->admission_status->value,
            ],
            'categories' => $groups,
            'summary' => [
                'required_count' => $required,
                'submitted_count' => $submitted,
                'missing_count' => $required - $submitted,
                'complete' => $required > 0 && $submitted === $required,
            ],
        ];
    }
}
