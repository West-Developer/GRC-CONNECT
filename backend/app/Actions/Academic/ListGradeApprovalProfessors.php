<?php

namespace App\Actions\Academic;

use App\Domain\Academic\GradeStatus;
use App\Models\AcademicGrade;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The Registrar Head's grade approvals, one row per professor.
 *
 * The approvals screen used to build its professor tiles from one page of the
 * flat grade list, so a professor whose grades straddled a page boundary showed
 * up twice with partial counts, and "Page 1 of 105" counted grade pages rather
 * than professors. Grouping here, in SQL, gives every professor exactly one row
 * with their real totals and pages over professors.
 *
 * A grade's professor is its section's professor, falling back to whoever
 * encoded it (the same rule `AcademicGradeResource` uses for `professor_id`).
 * The department filter matches a grade the same way `ListAcademicGrades` does.
 */
final readonly class ListGradeApprovalProfessors
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{paginator: LengthAwarePaginator<int, stdClass>, total_grades: int}
     */
    public function execute(User $actor, array $filters): array
    {
        $status = isset($filters['status']) ? (string) $filters['status'] : GradeStatus::Submitted->value;
        $academicTermId = isset($filters['academic_term_id']) ? (int) $filters['academic_term_id'] : null;
        $college = isset($filters['college']) ? strtolower(trim((string) $filters['college'])) : null;
        $search = isset($filters['search']) ? trim((string) $filters['search']) : null;
        $page = isset($filters['page']) ? (int) $filters['page'] : 1;
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 12;

        $professorKey = 'coalesce(sections.professor_id, academic_grades.encoded_by)';

        /** @var Builder<AcademicGrade> $filtered */
        $filtered = AcademicGrade::query()
            ->visibleTo($actor)
            ->where('academic_grades.status', $status)
            ->when($academicTermId !== null, fn ($query) => $query->where('academic_grades.academic_term_id', $academicTermId))
            ->when($college !== null && $college !== '' && $college !== 'all', function ($query) use ($college) {
                $query->where(function ($q) use ($college) {
                    $q->whereHas('section.sectionPlan', fn ($spq) => $spq->where('college', $college))
                        ->orWhereHas('subject', fn ($subq) => $subq->where('college', $college))
                        ->orWhereHas('student.program', fn ($pq) => $pq->where('college', $college));
                });
            });

        $totalGrades = (clone $filtered)->count();

        $professors = $filtered
            ->leftJoin('sections', 'sections.id', '=', 'academic_grades.section_id')
            ->leftJoin('academic_term_section_plans as plans', 'plans.id', '=', 'sections.section_plan_id')
            ->leftJoin('users as professors', 'professors.id', '=', DB::raw($professorKey))
            ->when($search !== null && $search !== '', fn ($query) => $query->where('professors.name', 'like', "%{$search}%"))
            ->groupBy(DB::raw($professorKey), 'professors.name')
            ->orderBy('professors.name')
            ->orderBy(DB::raw($professorKey))
            ->selectRaw("{$professorKey} as professor_id")
            ->selectRaw('professors.name as professor_name')
            ->selectRaw('max(plans.college) as college')
            ->selectRaw('count(distinct academic_grades.section_id) as subject_count')
            ->selectRaw('count(*) as grade_count')
            ->toBase()
            ->paginate($perPage, ['*'], 'page', $page);

        return ['paginator' => $professors, 'total_grades' => $totalGrades];
    }
}
