<?php

namespace App\Actions\Enrollment;

use App\Models\EnrollmentChangeRequest;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Role-scoped read (Student own, Registrar Head and Registrar Staff all —
 * `EnrollmentChangeRequest::scopeVisibleTo`) plus optional filters and
 * pagination, the same shape as `ListWithdrawalRequests`.
 */
final readonly class ListEnrollmentChangeRequests
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, EnrollmentChangeRequest>
     */
    public function execute(User $actor, array $filters): LengthAwarePaginator
    {
        $status = isset($filters['status']) ? (string) $filters['status'] : null;
        $type = isset($filters['type']) ? (string) $filters['type'] : null;
        $page = isset($filters['page']) ? (int) $filters['page'] : 1;
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;

        return EnrollmentChangeRequest::query()
            ->visibleTo($actor)
            ->with(['enrollment.student', 'subject', 'fromSection', 'toSection'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($type !== null, fn ($query) => $query->where('type', $type))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();
    }
}
