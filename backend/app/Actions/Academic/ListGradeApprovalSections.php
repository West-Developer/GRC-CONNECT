<?php

namespace App\Actions\Academic;

use App\Domain\Academic\GradeStatus;
use App\Models\AcademicGrade;
use App\Models\User;
use Illuminate\Support\Collection;
use stdClass;

/**
 * The second level of the Registrar Head's grade approvals: the sections (a
 * subject taught in a section) one professor has grades awaiting lock in, with
 * how many. Same professor and department rules as `ListGradeApprovalProfessors`.
 */
final readonly class ListGradeApprovalSections
{
    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, stdClass>
     */
    public function execute(User $actor, array $filters): Collection
    {
        $professorId = (int) $filters['professor_id'];
        $status = isset($filters['status']) ? (string) $filters['status'] : GradeStatus::Submitted->value;
        $academicTermId = isset($filters['academic_term_id']) ? (int) $filters['academic_term_id'] : null;
        $college = isset($filters['college']) ? strtolower(trim((string) $filters['college'])) : null;

        return AcademicGrade::query()
            ->visibleTo($actor)
            ->where('academic_grades.status', $status)
            ->when($academicTermId !== null, fn ($query) => $query->where('academic_grades.academic_term_id', $academicTermId))
            ->when($college !== null && $college !== '' && $college !== 'all', function ($query) use ($college) {
                $query->where(function ($q) use ($college) {
                    $q->whereHas('section.sectionPlan', fn ($spq) => $spq->where('college', $college))
                        ->orWhereHas('subject', fn ($subq) => $subq->where('college', $college))
                        ->orWhereHas('student.program', fn ($pq) => $pq->where('college', $college));
                });
            })
            ->leftJoin('sections', 'sections.id', '=', 'academic_grades.section_id')
            ->join('subjects', 'subjects.id', '=', 'academic_grades.subject_id')
            ->whereRaw('coalesce(sections.professor_id, academic_grades.encoded_by) = ?', [$professorId])
            ->groupBy('academic_grades.section_id', 'subjects.id', 'subjects.code', 'subjects.title', 'sections.section_code')
            ->orderBy('subjects.code')
            ->orderBy('sections.section_code')
            ->selectRaw('academic_grades.section_id as section_id')
            ->selectRaw('subjects.id as subject_id')
            ->selectRaw('subjects.code as subject_code')
            ->selectRaw('subjects.title as subject_title')
            ->selectRaw('sections.section_code as section_code')
            ->selectRaw('count(*) as grade_count')
            ->toBase()
            ->limit(500)
            ->get();
    }
}
