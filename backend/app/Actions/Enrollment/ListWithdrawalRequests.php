<?php

namespace App\Actions\Enrollment;

use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Role-scoped read (Student own, Registrar Staff and Registrar Head all —
 * `WithdrawalRequest::scopeVisibleTo`) plus optional filters and pagination,
 * the same shape as `ListEnrollments`/`ListAcademicGrades`.
 */
final readonly class ListWithdrawalRequests
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, WithdrawalRequest>
     */
    public function execute(User $actor, array $filters): LengthAwarePaginator
    {
        $status = isset($filters['status']) ? (string) $filters['status'] : null;
        $page = isset($filters['page']) ? (int) $filters['page'] : 1;
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;

        return WithdrawalRequest::query()
            ->visibleTo($actor)
            ->with(['enrollment.student'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();
    }
}
