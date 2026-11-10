<?php

namespace App\Actions\Academic;

use App\Models\TransfereeCredit;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Role-scoped read (Student own, Registrar Staff and Registrar Head all —
 * `TransfereeCredit::scopeVisibleTo`) plus optional filters and pagination,
 * the same shape as `ListWithdrawalRequests`/`ListAcademicGrades`.
 */
final readonly class ListTransfereeCredits
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, TransfereeCredit>
     */
    public function execute(User $actor, array $filters): LengthAwarePaginator
    {
        $studentId = isset($filters['student_id']) ? (int) $filters['student_id'] : null;
        $status = isset($filters['status']) ? (string) $filters['status'] : null;
        $page = isset($filters['page']) ? (int) $filters['page'] : 1;
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;

        return TransfereeCredit::query()
            ->visibleTo($actor)
            ->with(['student.user', 'subject'])
            ->when($studentId !== null, fn ($query) => $query->where('student_id', $studentId))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();
    }
}
