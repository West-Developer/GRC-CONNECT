<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\User;

/**
 * Computed views, not stored resources — the same shape as
 * EligibleSubjectPolicy and FacultyMemberPolicy. Each dashboard method is
 * role-scoped by design: Enrollment Summary is shared between Dean and
 * Executive Director (both are authorized to see enrollment-level activity
 * per PRD §3.5/§3.6); Analytics is shared by Program Chair and Registrar
 * Head with a narrower scope enforced by its controller; Institution Summary
 * and Policy Settings are each scoped to exactly one role.
 */
final class DashboardPolicy
{
    public function viewEnrollmentSummary(User $user): bool
    {
        return in_array($user->role, [
            UserRole::Dean,
            UserRole::ExecutiveDirector,
            UserRole::ProgramChair,
            UserRole::RegistrarHead,
        ], true);
    }

    /**
     * The Enrollment Dashboard itself (stakeholder Doc 14): the roles that see
     * every stage, plus Registrar Staff, Accounting Staff, and Admission Staff,
     * who see only the students waiting on them. That stage limit is applied by
     * `EnrollmentStatusPopulation`, not here. The Enrollment Summary (section
     * fill, grade submission) stays with `viewEnrollmentSummary`.
     */
    public function viewEnrollmentStatus(User $user): bool
    {
        return $this->viewEnrollmentSummary($user)
            || in_array($user->role, [
                UserRole::RegistrarStaff,
                UserRole::AccountingStaff,
                UserRole::AdmissionStaff,
            ], true);
    }

    /**
     * The student level of the Enrollment Dashboard drill-down — the audited
     * exception to ADR 0017's aggregate-only rule, defined in ADR 0024. Kept as
     * its own ability so it can be narrowed or revoked without touching the
     * aggregate one. Which students a caller may see is a college scope applied
     * by `EnrollmentStatusPopulation::scopeFor()`, not decided here.
     */
    public function viewEnrollmentStatusStudents(User $user): bool
    {
        return $this->viewEnrollmentStatus($user);
    }

    /**
     * Drops, withdrawals, and course shifts in Enrollment Analytics (Doc 14, S20):
     * both Registrar roles for every college, and a Program Head for their own.
     */
    public function viewEnrollmentMovements(User $user): bool
    {
        return $user->role === UserRole::RegistrarHead
            || $user->role === UserRole::RegistrarStaff
            || ($user->role === UserRole::ProgramChair && $user->college !== null);
    }

    /** Recording a course shift is the Registrar's (Head or Staff). */
    public function recordProgramShift(User $user): bool
    {
        return $user->role === UserRole::RegistrarHead || $user->role === UserRole::RegistrarStaff;
    }

    public function viewInstitutionSummary(User $user): bool
    {
        return $user->role === UserRole::ExecutiveDirector;
    }

    public function viewPolicySettings(User $user): bool
    {
        return $user->role === UserRole::RegistrarHead;
    }

    public function viewAnalytics(User $user): bool
    {
        return in_array(
            $user->role,
            [UserRole::ProgramChair, UserRole::RegistrarHead],
            true,
        );
    }
}
