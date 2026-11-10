<?php

namespace App\Actions\ItControl;

use App\Domain\Organization\AcademicTermStatus;
use App\Models\StudentProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

final readonly class ListStudentAccounts
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, StudentProfile>
     */
    public function execute(array $filters): LengthAwarePaginator
    {
        $search = isset($filters['q']) ? (string) $filters['q'] : null;
        $college = isset($filters['college']) ? (string) $filters['college'] : null;
        $programId = isset($filters['program_id']) ? (int) $filters['program_id'] : null;
        $yearLevel = isset($filters['year_level']) ? (int) $filters['year_level'] : null;
        $enrollmentCategory = isset($filters['enrollment_category']) ? (string) $filters['enrollment_category'] : null;
        $status = isset($filters['status']) ? (string) $filters['status'] : null;
        $page = isset($filters['page']) ? (int) $filters['page'] : 1;
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;

        return DB::transaction(function () use (
            $search,
            $college,
            $programId,
            $yearLevel,
            $enrollmentCategory,
            $status,
            $page,
            $perPage,
        ): LengthAwarePaginator {
            $query = StudentProfile::query()
                ->with([
                    'user:id,name,first_name,last_name,suffix,email,status,archived_at',
                    'program:id,code,college',
                    'enrollments' => function (HasMany $query): void {
                        $query
                            ->whereHas('academicTerm', fn (Builder $termQuery): Builder => $termQuery
                                ->where('status', AcademicTermStatus::SemesterOngoing->value))
                            ->orderByDesc('id');
                    },
                ])
                ->when($college !== null, fn (Builder $query): Builder => $query
                    ->whereHas('program', fn (Builder $programQuery): Builder => $programQuery->where('college', $college)))
                ->when($programId !== null, fn (Builder $query): Builder => $query->where('program_id', $programId))
                ->when($yearLevel !== null, fn (Builder $query): Builder => $query->where('year_level', $yearLevel))
                ->when($enrollmentCategory !== null, fn (Builder $query): Builder => $query->where('enrollment_category', $enrollmentCategory))
                // Archived accounts leave every view but their own (ADR 0054).
                ->whereHas('user', fn (Builder $userQuery): Builder => $status === 'archived'
                    ? $userQuery->whereNotNull('archived_at')
                    : $userQuery->whereNull('archived_at')->when($status !== null, fn (Builder $q): Builder => $q->where('status', $status)))
                ->when($search !== null, function (Builder $query) use ($search): Builder {
                    $pattern = '%'.$search.'%';

                    return $query->where(function (Builder $searchQuery) use ($pattern): void {
                        $searchQuery
                            ->where('student_number', 'like', $pattern)
                            ->orWhereHas('user', fn (Builder $userQuery): Builder => $userQuery
                                ->where('name', 'like', $pattern)
                                ->orWhere('email', 'like', $pattern));
                    });
                })
                ->orderBy('student_number')
                ->orderBy('id');

            return $query->paginate($perPage, ['*'], 'page', $page)->withQueryString();
        });
    }
}
