<?php

namespace App\Actions\Dashboard;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Dashboard\EnrollmentStatusGroup;
use App\Domain\Organization\CollegeCode;
use App\Models\AcademicTerm;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Third level of the drill-down: the students of one department, optionally
 * narrowed to a section (or to "no section yet") and to one group. This is the
 * first place the dashboard exposes identities to Dean and Executive Director,
 * under ADR 0024: read-only, institution-wide for Dean/Executive Director/
 * Registrar Head, own college for Program Chair, a fixed narrow field set, and
 * an audit entry whenever a list is opened (first page only, so paging through
 * one list is one audit row).
 */
final readonly class ListEnrollmentStatusStudents
{
    public function __construct(
        private EnrollmentStatusPopulation $population,
        private AuditRecorder $auditRecorder,
    ) {}

    /**
     * @return LengthAwarePaginator<int, object>
     *
     * @throws AuthorizationException when the actor is limited to another college
     */
    public function execute(
        AcademicTerm $term,
        User $actor,
        AuditRequestContext $context,
        CollegeCode $department,
        ?string $sectionCode,
        bool $withoutSection,
        ?EnrollmentStatusGroup $group,
        int $page,
        int $perPage,
    ): LengthAwarePaginator {
        $scope = $this->population->scopeFor($actor);

        if ($scope !== null && $scope !== $department) {
            throw new AuthorizationException('You can only view your own college.');
        }

        return DB::transaction(function () use (
            $term, $actor, $context, $department, $sectionCode, $withoutSection, $group, $page, $perPage,
        ): LengthAwarePaginator {
            $query = $this->population->query($term, $department, $actor)
                ->select(
                    'sp.id as student_profile_id',
                    'sp.student_number',
                    'u.name as student_name',
                    'p.code as program_code',
                    'p.name as program_name',
                    'p.college as department',
                    'sp.year_level',
                    'ps.section_code',
                    'ce.id as enrollment_id',
                    'ce.status as enrollment_status',
                    'ce.submitted_at',
                    'ce.enrolled_at',
                )
                ->when($sectionCode !== null, fn ($q) => $q->where('ps.section_code', $sectionCode))
                ->when($withoutSection, fn ($q) => $q->whereNull('ps.section_code'))
                ->orderBy('u.name')
                ->orderBy('sp.id');

            if ($group !== null) {
                $this->population->whereGroup($query, $group);
            }

            $students = $query->paginate($perPage, ['*'], 'page', $page);

            if ($page === 1) {
                $this->auditRecorder->record(
                    $actor,
                    AuditAction::ENROLLMENT_STATUS_STUDENT_LIST_VIEWED,
                    AuditableType::ENROLLMENT_STATUS_DASHBOARD,
                    null,
                    null,
                    [
                        'academic_term_id' => $term->id,
                        'department' => $department->value,
                        'section_code' => $sectionCode,
                        'without_section' => $withoutSection,
                        'group' => $group?->value,
                        'result_count' => $students->total(),
                    ],
                    null,
                    $context,
                );
            }

            return $students;
        });
    }
}
