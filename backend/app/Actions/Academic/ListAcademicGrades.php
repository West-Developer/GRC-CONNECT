<?php

namespace App\Actions\Academic;

use App\Models\AcademicGrade;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Role-scoped read (Student own, Faculty own sections, Registrar Head all —
 * `AcademicGrade::scopeVisibleTo`) plus optional filters and pagination, the
 * same shape as `App\Actions\Enrollment\ListEnrollments`.
 */
final readonly class ListAcademicGrades
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, AcademicGrade>
     */
    public function execute(User $actor, array $filters): LengthAwarePaginator
    {
        $studentId = isset($filters['student_id']) ? (int) $filters['student_id'] : null;
        $subjectId = isset($filters['subject_id']) ? (int) $filters['subject_id'] : null;
        $professorId = isset($filters['professor_id']) ? (int) $filters['professor_id'] : null;
        $sectionId = isset($filters['section_id']) ? (int) $filters['section_id'] : null;
        $academicTermId = isset($filters['academic_term_id']) ? (int) $filters['academic_term_id'] : null;
        $status = isset($filters['status']) ? (string) $filters['status'] : null;
        $search = isset($filters['search']) ? trim((string) $filters['search']) : null;
        $college = isset($filters['college']) ? strtolower(trim((string) $filters['college'])) : null;
        $page = isset($filters['page']) ? (int) $filters['page'] : 1;
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;

        return AcademicGrade::query()
            ->visibleTo($actor)
            ->with(['student.user', 'student.program', 'subject', 'section.professor', 'section.sectionPlan', 'academicTerm', 'encoder'])
            ->when($studentId !== null, fn ($query) => $query->where('student_id', $studentId))
            ->when($subjectId !== null, fn ($query) => $query->where('subject_id', $subjectId))
            ->when($sectionId !== null, fn ($query) => $query->where('section_id', $sectionId))
            // A grade's professor is its section's professor, falling back to its encoder
            // (the rule `AcademicGradeResource` uses for `professor_id`).
            ->when($professorId !== null, function ($query) use ($professorId) {
                $query->where(function ($q) use ($professorId) {
                    $q->whereHas('section', fn ($sq) => $sq->where('professor_id', $professorId))
                        ->orWhere(function ($fallback) use ($professorId) {
                            $fallback->where('encoded_by', $professorId)
                                ->whereDoesntHave('section', fn ($sq) => $sq->whereNotNull('professor_id'));
                        });
                });
            })
            ->when($academicTermId !== null, fn ($query) => $query->where('academic_term_id', $academicTermId))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($search !== null && $search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->whereHas('student', fn ($sq) => $sq->where('student_number', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$search}%")))
                        ->orWhereHas('subject', fn ($subq) => $subq->where('code', 'like', "%{$search}%")->orWhere('title', 'like', "%{$search}%"));
                });
            })
            ->when($college !== null && $college !== '' && $college !== 'all', function ($query) use ($college) {
                $query->where(function ($q) use ($college) {
                    $q->whereHas('section.sectionPlan', fn ($spq) => $spq->where('college', $college))
                        ->orWhereHas('subject', fn ($subq) => $subq->where('college', $college))
                        ->orWhereHas('student.program', fn ($pq) => $pq->where('college', $college));
                });
            })
            ->orderByDesc('academic_term_id')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();
    }
}
