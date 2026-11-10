<?php

namespace App\Actions\Enrollment;

use App\Models\EnrollmentSubject;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Role-scoped read (Faculty own sections, Registrar Staff and Registrar
 * Head all — `EnrollmentSubject::scopeVisibleTo`) plus optional filters and
 * pagination, the same shape as `ListAcademicGrades`/`ListWithdrawalRequests`.
 */
final readonly class ListClassRoster
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, EnrollmentSubject>
     */
    public function execute(User $actor, array $filters): LengthAwarePaginator
    {
        $sectionId = isset($filters['section_id']) ? (int) $filters['section_id'] : null;
        $academicTermId = isset($filters['academic_term_id']) ? (int) $filters['academic_term_id'] : null;
        $page = isset($filters['page']) ? (int) $filters['page'] : 1;
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;

        return EnrollmentSubject::query()
            ->visibleTo($actor)
            ->with(['enrollment.student.user', 'section.subject'])
            ->when($sectionId !== null, fn ($query) => $query->where('section_id', $sectionId))
            ->when(
                $academicTermId !== null,
                fn ($query) => $query->whereHas('section', fn ($sectionQuery) => $sectionQuery->where('academic_term_id', $academicTermId)),
            )
            ->orderBy('section_id')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();
    }
}
