<?php

namespace App\Actions\Identity;

use App\Models\StudentProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Registrar's read-only list of every student, searchable by student number or name. The
 * Registrar Head opens a row for the student's details; this list changes nothing.
 */
final readonly class ListRegistrarStudents
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, StudentProfile>
     */
    public function execute(array $filters): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);

        return StudentProfile::query()
            ->select('student_profiles.*')
            ->with(['user', 'program'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $pattern = '%'.$search.'%';
                $query->where(function (Builder $searchQuery) use ($pattern): void {
                    $searchQuery
                        ->where('student_profiles.student_number', 'like', $pattern)
                        ->orWhereHas('user', fn (Builder $userQuery): Builder => $userQuery->where('name', 'like', $pattern));
                });
            })
            ->orderByDesc('student_profiles.student_number')
            ->orderByDesc('student_profiles.id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();
    }
}
