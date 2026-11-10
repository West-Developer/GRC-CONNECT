<?php

namespace App\Actions\Dashboard;

use App\Domain\Dashboard\EnrollmentStatusGroup;
use App\Domain\Dashboard\EnrollmentStatusSectionBreakdown;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Organization\CollegeCode;
use App\Models\AcademicTerm;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Second level of the drill-down: the sections of one department with their
 * students grouped by `EnrollmentStatusGroup`. Still aggregate-only.
 */
final readonly class BuildEnrollmentStatusSections
{
    public function __construct(private EnrollmentStatusPopulation $population) {}

    /**
     * @throws AuthorizationException when the actor is limited to another college
     */
    public function execute(AcademicTerm $term, User $actor, CollegeCode $department): EnrollmentStatusSectionBreakdown
    {
        $scope = $this->population->scopeFor($actor);

        if ($scope !== null && $scope !== $department) {
            throw new AuthorizationException('You can only view your own college.');
        }

        $rows = $this->population->query($term, $department, $actor)
            ->select('ps.section_code', 'ce.status as enrollment_status')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('ps.section_code', 'ce.status')
            ->get();

        /** @var array<string, array<string, int>> $bySection keyed by section code, '' for none */
        $bySection = [];

        foreach ($rows as $row) {
            $status = $row->enrollment_status === null
                ? null
                : EnrollmentStatus::tryFrom((string) $row->enrollment_status);
            $group = EnrollmentStatusGroup::forStatus($status);

            $key = $row->section_code === null ? '' : (string) $row->section_code;
            $bySection[$key] ??= EnrollmentStatusGroup::emptyCounts();
            $bySection[$key][$group->value] += (int) $row->aggregate;
        }

        // Real sections in code order, then the "No section yet" row last.
        $codes = array_filter(array_keys($bySection), fn (string|int $code): bool => $code !== '');
        sort($codes, SORT_NATURAL | SORT_FLAG_CASE);

        $sections = [];
        foreach ($codes as $code) {
            $sections[] = [
                'section_code' => (string) $code,
                'total' => array_sum($bySection[$code]),
                'groups' => $bySection[$code],
            ];
        }

        if (isset($bySection[''])) {
            $sections[] = [
                'section_code' => null,
                'total' => array_sum($bySection['']),
                'groups' => $bySection[''],
            ];
        }

        return new EnrollmentStatusSectionBreakdown($term->id, $department->value, $sections);
    }
}
