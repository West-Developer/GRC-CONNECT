<?php

namespace App\Actions\Academic;

use App\Models\StudentTorDocument;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Role-scoped read of uploaded Transcripts of Records
 * (`StudentTorDocument::scopeVisibleTo`), newest first, optionally for one student.
 */
final readonly class ListTorDocuments
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, StudentTorDocument>
     */
    public function execute(User $actor, array $filters): LengthAwarePaginator
    {
        $studentId = isset($filters['student_id']) ? (int) $filters['student_id'] : null;
        $page = isset($filters['page']) ? (int) $filters['page'] : 1;
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 50;

        return StudentTorDocument::query()
            ->visibleTo($actor)
            ->with(['student.user'])
            ->when($studentId !== null, fn ($query) => $query->where('student_id', $studentId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();
    }
}
