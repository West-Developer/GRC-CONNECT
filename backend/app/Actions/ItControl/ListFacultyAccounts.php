<?php

namespace App\Actions\ItControl;

use App\Domain\Identity\UserRole;
use App\Models\FacultyAvailability;
use App\Models\FacultySpecialization;
use App\Models\FacultySubjectPreference;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class ListFacultyAccounts
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function execute(array $filters): LengthAwarePaginator
    {
        $search = isset($filters['q']) ? (string) $filters['q'] : null;
        $college = isset($filters['college']) ? (string) $filters['college'] : null;
        $employmentType = isset($filters['employment_type']) ? (string) $filters['employment_type'] : null;
        $status = isset($filters['status']) ? (string) $filters['status'] : null;
        $page = isset($filters['page']) ? (int) $filters['page'] : 1;
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;

        return DB::transaction(function () use (
            $search,
            $college,
            $employmentType,
            $status,
            $page,
            $perPage,
        ): LengthAwarePaginator {
            $query = User::query()
                ->select('users.*')
                ->selectSub(
                    FacultyAvailability::query()
                        ->selectRaw('count(*)')
                        ->whereColumn('professor_id', 'users.id'),
                    'availability_window_count',
                )
                ->selectSub(
                    FacultySubjectPreference::query()
                        ->selectRaw('count(*)')
                        ->whereColumn('professor_id', 'users.id'),
                    'subject_preference_count',
                )
                ->selectSub(
                    FacultySpecialization::query()
                        ->selectRaw('count(*)')
                        ->whereColumn('professor_id', 'users.id'),
                    'specialization_count',
                )
                ->where('role', UserRole::Faculty)
                ->when($college !== null, fn (Builder $query): Builder => $query->where('college', $college))
                ->when($employmentType !== null, fn (Builder $query): Builder => $query->where('employment_type', $employmentType))
                // Archived accounts leave every view but their own (ADR 0054).
                ->when($status === 'archived', fn (Builder $query): Builder => $query->whereNotNull('archived_at'))
                ->when($status !== 'archived', fn (Builder $query): Builder => $query->whereNull('archived_at')
                    ->when($status !== null, fn (Builder $q): Builder => $q->where('status', $status)))
                ->when($search !== null, function (Builder $query) use ($search): Builder {
                    $pattern = '%'.$search.'%';

                    return $query->where(fn (Builder $searchQuery): Builder => $searchQuery
                        ->where('name', 'like', $pattern)
                        ->orWhere('email', 'like', $pattern));
                })
                ->orderBy('name')
                ->orderBy('id');

            return $query->paginate($perPage, ['*'], 'page', $page)->withQueryString();
        });
    }
}
