<?php

namespace App\Http\Resources\Api\V1;

use App\Models\StudentProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read StudentProfile $resource
 */
final class AcademicRecordStudentResource extends JsonResource
{
    /**
     * @return array{
     *     type: string,
     *     id: int,
     *     student_id: int,
     *     student_number: string,
     *     name: string,
     *     first_name: string,
     *     last_name: string,
     *     email: string,
     *     program_code: string,
     *     program_name: string,
     *     year_level: int,
     *     enrollment_category: ?string,
     *     enrollment_category_label: ?string,
     *     academic_standing: string,
     *     academic_standing_label: string
     * }
     */
    public function toArray(Request $request): array
    {
        $profile = $this->resource;
        $user = $profile->user;
        $program = $profile->program;

        return [
            'type' => 'academic_record_student',
            'id' => $profile->id,
            'student_id' => $profile->id,
            'student_number' => $profile->student_number,
            'name' => $user->name,
            'first_name' => $user->first_name ?? $user->name,
            'last_name' => $user->last_name ?? '',
            'email' => $user->email,
            'program_code' => $program->code,
            'program_name' => $program->name,
            'year_level' => $profile->year_level,
            'enrollment_category' => $profile->enrollment_category,
            'enrollment_category_label' => $profile->enrollment_category !== null
                ? ucfirst($profile->enrollment_category)
                : null,
            'academic_standing' => $profile->academic_standing->value,
            'academic_standing_label' => $profile->academic_standing->label(),
        ];
    }
}
