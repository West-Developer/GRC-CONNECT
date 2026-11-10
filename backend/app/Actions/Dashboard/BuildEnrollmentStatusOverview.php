<?php

namespace App\Actions\Dashboard;

use App\Domain\Dashboard\EnrollmentStatusGroup;
use App\Domain\Dashboard\EnrollmentStatusOverview;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Organization\CollegeCode;
use App\Models\AcademicTerm;
use App\Models\User;

/**
 * Aggregate-only, like `BuildEnrollmentSummary` (ADR 0017): counts of students
 * by `EnrollmentStatusGroup`, the per-step split, and the same groups per
 * department. No identity leaves this Action; see `ListEnrollmentStatusStudents`
 * for the audited student level (ADR 0024).
 */
final readonly class BuildEnrollmentStatusOverview
{
    /** The steps of the in-progress chart: every non-terminal status, ending at Enrolled. */
    private const STEPS = [
        EnrollmentStatus::Draft,
        EnrollmentStatus::PendingProgramHeadApproval,
        EnrollmentStatus::PendingStudentReview,
        EnrollmentStatus::AwaitingFirstPayment,
        EnrollmentStatus::PendingRegistrarApproval,
        EnrollmentStatus::PendingPayment,
        EnrollmentStatus::Enrolled,
    ];

    public function __construct(private EnrollmentStatusPopulation $population) {}

    public function execute(AcademicTerm $term, User $actor): EnrollmentStatusOverview
    {
        $scope = $this->population->scopeFor($actor);

        $rows = $this->population->query($term, $scope, $actor)
            ->select('p.college as department', 'ce.status as enrollment_status')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('p.college', 'ce.status')
            ->get();

        $groups = EnrollmentStatusGroup::emptyCounts();
        $steps = [];
        foreach (self::STEPS as $step) {
            $steps[$step->value] = 0;
        }

        $emptySteps = $steps;

        /** @var array<string, array<string, int>> $byDepartment keyed by college value, '' for none */
        $byDepartment = [];
        /** @var array<string, array<string, int>> $stepsByDepartment keyed by college value, '' for none */
        $stepsByDepartment = [];

        foreach ($rows as $row) {
            $status = $row->enrollment_status === null
                ? null
                : EnrollmentStatus::tryFrom((string) $row->enrollment_status);
            $group = EnrollmentStatusGroup::forStatus($status);
            $count = (int) $row->aggregate;

            $groups[$group->value] += $count;

            $key = $row->department === null ? '' : (string) $row->department;
            $stepsByDepartment[$key] ??= $emptySteps;

            if ($status !== null && array_key_exists($status->value, $steps)) {
                $steps[$status->value] += $count;
                $stepsByDepartment[$key][$status->value] += $count;
            }

            $byDepartment[$key] ??= EnrollmentStatusGroup::emptyCounts();
            $byDepartment[$key][$group->value] += $count;
        }

        $departments = [];
        foreach ($scope !== null ? [$scope] : CollegeCode::cases() as $college) {
            $departmentGroups = $byDepartment[$college->value] ?? EnrollmentStatusGroup::emptyCounts();
            $departments[] = [
                'department' => $college->value,
                'label' => $college->label(),
                'total' => array_sum($departmentGroups),
                'groups' => $departmentGroups,
                'steps' => $stepsByDepartment[$college->value] ?? $emptySteps,
            ];
        }

        // Students whose program has no supported college (see CollegeCode).
        // Only unscoped roles can see them, and only when there are any.
        if ($scope === null && isset($byDepartment[''])) {
            $departments[] = [
                'department' => null,
                'label' => 'No department',
                'total' => array_sum($byDepartment['']),
                'groups' => $byDepartment[''],
                'steps' => $stepsByDepartment[''] ?? $emptySteps,
            ];
        }

        return new EnrollmentStatusOverview(
            academicTermId: $term->id,
            totalStudents: array_sum($groups),
            groups: $groups,
            steps: $steps,
            departments: $departments,
        );
    }
}
