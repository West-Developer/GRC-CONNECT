<?php

namespace App\Http\Resources\Api\V1;

use App\Models\StudentProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the Registrar's student list. Needs the profile loaded with its user and program.
 * It carries only what the list shows; the Registrar Head's details dialog reads the full profile.
 *
 * @property-read StudentProfile $resource
 */
final class RegistrarStudentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profile = $this->resource;

        return [
            'type' => 'registrar_student',
            'id' => $profile->id,
            'student_number' => $profile->student_number,
            'name' => $profile->user->name,
            'program_code' => $profile->program->code,
            'year_level' => $profile->year_level,
            'enrollment_category' => $profile->enrollment_category,
            'academic_standing' => $profile->academic_standing->value,
            'academic_standing_label' => $profile->academic_standing->label(),
        ];
    }
}
