<?php

namespace App\Actions\Analytics;

use App\Domain\Enrollment\EnrollmentChangeRequestStatus;
use App\Domain\Enrollment\EnrollmentChangeRequestType;
use App\Domain\Enrollment\WithdrawalStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Organization\CollegeCode;
use App\Models\AcademicTerm;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Aggregate counts of students leaving a course of study or a subject, for
 * Enrollment Analytics (stakeholder Doc 14, ADR 0034): approved drops,
 * approved withdrawals, and recorded course shifts (from course, to course).
 * Counts only, like the other analytics (ADR 0017): no student is identified.
 * The Registrar Head and Registrar Staff see every college; a Program Head
 * sees only their own.
 */
final readonly class BuildEnrollmentMovementReport
{
    public const DROPS = 'drops';

    public const WITHDRAWALS = 'withdrawals';

    public const SHIFTS = 'shifts';

    /**
     * @return array{
     *     movement: string,
     *     academic_term_id: int,
     *     total: int,
     *     by_department: list<array{college: string, label: string, count: int}>,
     *     groups: list<array{label: string, count: int, from_program_code: ?string, to_program_code: ?string}>
     * }
     */
    public function execute(User $actor, AcademicTerm $term, string $movement, ?string $college): array
    {
        $scope = $actor->role === UserRole::ProgramChair ? $actor->college?->value : $college;

        $rows = match ($movement) {
            self::DROPS => $this->drops($term, $scope),
            self::WITHDRAWALS => $this->withdrawals($term, $scope),
            default => $this->shifts($term, $scope),
        };

        $byDepartment = [];
        foreach ($rows as $row) {
            $key = (string) ($row->college ?? '');
            $byDepartment[$key] = ($byDepartment[$key] ?? 0) + (int) $row->aggregate;
        }

        $departments = [];
        foreach (CollegeCode::cases() as $code) {
            if ($scope !== null && $scope !== $code->value) {
                continue;
            }
            $departments[] = ['college' => $code->value, 'label' => $code->label(), 'count' => $byDepartment[$code->value] ?? 0];
        }

        $groups = [];
        foreach ($rows as $row) {
            $groups[] = [
                'label' => $movement === self::SHIFTS
                    ? "{$row->from_code} → {$row->to_code}"
                    : (string) $row->program_code,
                'count' => (int) $row->aggregate,
                'from_program_code' => $movement === self::SHIFTS ? (string) $row->from_code : null,
                'to_program_code' => $movement === self::SHIFTS ? (string) $row->to_code : null,
            ];
        }
        usort($groups, fn (array $a, array $b): int => [$b['count'], $a['label']] <=> [$a['count'], $b['label']]);

        return [
            'movement' => $movement,
            'academic_term_id' => $term->id,
            'total' => (int) array_sum(array_column($groups, 'count')),
            'by_department' => $departments,
            'groups' => $groups,
        ];
    }

    /** @return iterable<int, stdClass> */
    private function drops(AcademicTerm $term, ?string $scope): iterable
    {
        return $this->byStudentProgram(
            DB::table('enrollment_change_requests as r')
                ->join('enrollments as e', 'e.id', '=', 'r.enrollment_id')
                ->where('r.type', EnrollmentChangeRequestType::Drop->value)
                ->where('r.status', EnrollmentChangeRequestStatus::Approved->value)
                ->where('e.academic_term_id', $term->id),
            $scope,
        );
    }

    /** @return iterable<int, stdClass> */
    private function withdrawals(AcademicTerm $term, ?string $scope): iterable
    {
        return $this->byStudentProgram(
            DB::table('withdrawal_requests as r')
                ->join('enrollments as e', 'e.id', '=', 'r.enrollment_id')
                ->where('r.status', WithdrawalStatus::Approved->value)
                ->where('e.academic_term_id', $term->id),
            $scope,
        );
    }

    /**
     * @return iterable<int, stdClass>
     */
    private function byStudentProgram(Builder $requests, ?string $scope): iterable
    {
        return $requests
            ->join('student_profiles as sp', 'sp.id', '=', 'e.student_id')
            ->join('programs as p', 'p.id', '=', 'sp.program_id')
            ->where('sp.is_demo_account', false)
            ->when($scope !== null, fn (Builder $query) => $query->where('p.college', $scope))
            ->groupBy('p.college', 'p.code')
            ->select('p.college as college', 'p.code as program_code')
            ->selectRaw('count(distinct e.student_id) as aggregate')
            ->get();
    }

    /** @return iterable<int, stdClass> */
    private function shifts(AcademicTerm $term, ?string $scope): iterable
    {
        return DB::table('program_shifts as s')
            ->join('programs as f', 'f.id', '=', 's.from_program_id')
            ->join('programs as t', 't.id', '=', 's.to_program_id')
            ->join('student_profiles as sp', 'sp.id', '=', 's.student_id')
            ->where('sp.is_demo_account', false)
            ->where('s.academic_term_id', $term->id)
            // A Program Head sees a shift out of, or into, their own college.
            ->when($scope !== null, fn (Builder $query) => $query->where(
                fn (Builder $either) => $either->where('f.college', $scope)->orWhere('t.college', $scope),
            ))
            ->groupBy('f.college', 'f.code', 't.code')
            ->select('f.college as college', 'f.code as from_code', 't.code as to_code')
            ->selectRaw('count(distinct s.student_id) as aggregate')
            ->get();
    }
}
