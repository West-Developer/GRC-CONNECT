<?php

namespace App\Actions\Academic;

use App\Domain\Identity\AdmissionStatus;
use App\Models\StudentProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class ListGraduates
{
    /**
     * @param  array{program_id?: ?int, graduation_school_year?: ?string, curriculum_id?: ?int, search?: ?string, per_page?: ?int}  $filters
     * @return LengthAwarePaginator<StudentProfile>
     */
    public function execute(array $filters = []): LengthAwarePaginator
    {
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));

        $query = StudentProfile::query()
            ->with(['user', 'program', 'curriculum', 'grades' => function ($q): void {
                $q->whereNotNull('final_grade');
            }])
            ->where('admission_status', AdmissionStatus::Graduated->value);

        if (! empty($filters['program_id'])) {
            $query->where('program_id', (int) $filters['program_id']);
        }

        if (! empty($filters['graduation_school_year'])) {
            $query->where('graduation_school_year', $filters['graduation_school_year']);
        }

        if (! empty($filters['curriculum_id'])) {
            $query->where('curriculum_id', (int) $filters['curriculum_id']);
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $sub) use ($search): void {
                $sub->where('student_number', 'like', "%{$search}%")
                    ->orWhereHas('user', function (Builder $userQuery) use ($search): void {
                        $userQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        return $query
            ->orderByDesc('graduation_school_year')
            ->orderBy('student_number')
            ->paginate($perPage);
    }
}

