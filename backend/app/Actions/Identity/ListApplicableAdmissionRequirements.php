<?php

namespace App\Actions\Identity;

use App\Domain\Identity\AdmissionRequirementCategory;
use App\Domain\Identity\StudentType;
use App\Models\AdmissionRequirementType;

/**
 * The Admission requirements a new student of a given type is asked for, before any student
 * record exists (the Create Account checklist, stakeholder Doc 20). Admission chooses the student
 * type on the form; the lists that apply come from `AdmissionRequirementCategory::forStudentType`.
 */
final class ListApplicableAdmissionRequirements
{
    /**
     * @return array{student_type: string, student_type_label: string, categories: list<array{category: string, label: string, items: list<array{requirement_type_id: int, name: string, is_system: bool}>}>}
     */
    public function execute(StudentType $studentType): array
    {
        $categories = AdmissionRequirementCategory::forStudentType($studentType);

        $types = AdmissionRequirementType::query()
            ->where('is_active', true)
            ->whereIn('category', array_map(static fn (AdmissionRequirementCategory $category): string => $category->value, $categories))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $groups = [];
        foreach ($categories as $category) {
            $groups[] = [
                'category' => $category->value,
                'label' => $category->label(),
                'items' => array_values($types
                    ->where('category', $category)
                    ->map(static fn (AdmissionRequirementType $type): array => [
                        'requirement_type_id' => $type->id,
                        'name' => $type->name,
                        'is_system' => $type->is_system,
                    ])
                    ->all()),
            ];
        }

        return [
            'student_type' => $studentType->value,
            'student_type_label' => $studentType->label(),
            'categories' => $groups,
        ];
    }

    /**
     * @return list<int>
     */
    public function applicableIds(StudentType $studentType): array
    {
        $ids = [];
        foreach ($this->execute($studentType)['categories'] as $group) {
            foreach ($group['items'] as $item) {
                $ids[] = $item['requirement_type_id'];
            }
        }

        return $ids;
    }
}
