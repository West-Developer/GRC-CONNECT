<?php

namespace App\Actions\Dashboard;

use App\Domain\Dashboard\EnrollmentStatusGroup;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CollegeCode;
use App\Models\AcademicTerm;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The single definition of "which students does the Enrollment Dashboard
 * count, and where does each one sit" — shared by the overview, section,
 * student-list and student-detail Actions so their numbers can never drift
 * apart.
 *
 * One row per student, exposing:
 *   `ce.*`  the student's *current* enrollment for the term: a non-terminal
 *           enrollment if there is one, otherwise the latest terminal one.
 *           All `null` when the student has no enrollment row.
 *   `ps.section_code`  the student's section — the code covering most of the
 *           current enrollment's non-dropped subjects (ties by code), so each
 *           student is in exactly one section. `null` = "No section yet".
 *   `p.college` the student's department (program college).
 *
 * Who is counted (ADR 0024): any student with a current enrollment this term,
 * plus every eligible student without one. Eligible = an active account, not
 * graduated, and not withdrawn from the school; demo accounts are excluded,
 * as in the Honors and Attrition reports. `AdmissionStatus` is a provisional
 * vocabulary (see its docblock), so revisit this filter when GRC confirms it.
 */
final readonly class EnrollmentStatusPopulation
{
    /**
     * The department this actor is limited to, or null for institution-wide.
     * Program Chair is limited to their own college (unscoped when they have
     * none assigned, matching `Section::scopeVisibleTo`). The Dean monitors
     * every department here, same as Registrar Head/Executive Director —
     * this is read-only reporting, distinct from the Dean's own-college
     * *approval* authority elsewhere (schedule/curriculum approvals, faculty
     * load overrides), which this method does not touch.
     */
    public function scopeFor(User $actor): ?CollegeCode
    {
        return match ($actor->role) {
            UserRole::ProgramChair => $actor->college,
            default => null,
        };
    }

    /**
     * Registrar Staff, Accounting Staff, and Admission Staff see the dashboard
     * too (stakeholder Doc 14), but only the students who are waiting on them:
     * the Registrar's approval stage, the payment stage, and students still in
     * admission. Every level of the drill-down passes the actor here so no
     * endpoint can be used to read a stage the role does not own. Null for the
     * roles that see every stage.
     *
     * @return list<EnrollmentStatus>|null enrollment statuses, or null when the stage is admission
     */
    public function stageStatusesFor(User $actor): ?array
    {
        return match ($actor->role) {
            UserRole::RegistrarStaff => [EnrollmentStatus::PendingRegistrarApproval],
            // A transferee's enrollment at the payment hold is waiting on the Cashier too.
            UserRole::AccountingStaff => [EnrollmentStatus::AwaitingFirstPayment, EnrollmentStatus::PendingPayment],
            default => null,
        };
    }

    /** Whether this role sees only the students waiting on it, not every stage. */
    public function isStageLimited(User $actor): bool
    {
        return $this->stageStatusesFor($actor) !== null || $this->isAdmissionStage($actor);
    }

    /** Whether this actor sees only students still in the admission process. */
    public function isAdmissionStage(User $actor): bool
    {
        return $actor->role === UserRole::AdmissionStaff;
    }

    /**
     * @param  ?User  $actor  when given, the role's stage limit is applied as well
     */
    public function query(AcademicTerm $term, ?CollegeCode $scope, ?User $actor = null): Builder
    {
        $terminal = $this->quotedList(EnrollmentStatusGroup::NotEnrolled->statuses());

        $current = DB::table('enrollments as e')
            ->where('e.academic_term_id', $term->id)
            ->whereRaw(
                'e.id = (select e2.id from enrollments as e2'
                .' where e2.student_id = e.student_id and e2.academic_term_id = e.academic_term_id'
                ."  order by case when e2.status in ({$terminal}) then 1 else 0 end asc, e2.id desc limit 1)",
            )
            ->select(
                'e.id',
                'e.student_id',
                'e.status',
                'e.total_units',
                'e.submitted_at',
                'e.registrar_decided_at',
                'e.payment_confirmed_at',
                'e.enrolled_at',
            );

        $sectionCounts = DB::table('enrollment_subjects as es')
            ->join('sections as s', 's.id', '=', 'es.section_id')
            ->where('es.status', '!=', EnrollmentSubjectStatus::Dropped->value)
            ->whereIn(
                'es.enrollment_id',
                DB::table('enrollments')->where('academic_term_id', $term->id)->select('id'),
            )
            ->selectRaw(
                'es.enrollment_id, s.section_code,'
                .' row_number() over (partition by es.enrollment_id order by count(*) desc, s.section_code asc) as rn',
            )
            ->groupBy('es.enrollment_id', 's.section_code');

        $primarySection = DB::query()
            ->fromSub($sectionCounts, 'ranked')
            ->where('ranked.rn', 1)
            ->select('ranked.enrollment_id', 'ranked.section_code');

        $query = DB::table('student_profiles as sp')
            ->join('users as u', 'u.id', '=', 'sp.user_id')
            ->join('programs as p', 'p.id', '=', 'sp.program_id')
            ->leftJoinSub($current, 'ce', 'ce.student_id', '=', 'sp.id')
            ->leftJoinSub($primarySection, 'ps', 'ps.enrollment_id', '=', 'ce.id')
            ->where('sp.is_demo_account', false)
            ->where(function (Builder $counted): void {
                $counted->whereNotNull('ce.id')
                    ->orWhere(function (Builder $eligible): void {
                        $eligible->where('u.status', UserStatus::Active->value)
                            ->whereNull('sp.graduation_school_year')
                            ->whereNotIn('sp.admission_status', [
                                AdmissionStatus::Graduated->value,
                                AdmissionStatus::Withdrawn->value,
                            ]);
                    });
            });

        if ($scope !== null) {
            $query->where('p.college', $scope->value);
        }

        if ($actor instanceof User) {
            $stage = $this->stageStatusesFor($actor);
            if ($stage !== null) {
                $query->whereIn('ce.status', array_map(fn (EnrollmentStatus $status): string => $status->value, $stage));
            }
            if ($this->isAdmissionStage($actor)) {
                $query->whereIn('sp.admission_status', [AdmissionStatus::Pending->value, AdmissionStatus::Admitted->value]);
            }
        }

        return $query;
    }

    /**
     * Narrows a `query()` to one group. `not_yet_done` is "no current
     * enrollment"; every other group is a set of enrollment statuses.
     */
    public function whereGroup(Builder $query, EnrollmentStatusGroup $group): Builder
    {
        if ($group === EnrollmentStatusGroup::NotYetDone) {
            return $query->whereNull('ce.id');
        }

        return $query->whereIn('ce.status', array_map(
            fn ($status): string => $status->value,
            $group->statuses(),
        ));
    }

    /**
     * Enum backing values only (never user input), so inlining them is safe
     * and keeps the correlated subquery free of positional bindings.
     *
     * @param  list<EnrollmentStatus>  $statuses
     */
    private function quotedList(array $statuses): string
    {
        return implode(', ', array_map(
            fn ($status): string => "'{$status->value}'",
            $statuses,
        ));
    }
}
