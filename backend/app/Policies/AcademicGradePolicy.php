<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\AcademicGrade;
use App\Models\User;

/**
 * Four roles may list grades (PRD §4.3, §5.3 DFD 3.1): the owning Student,
 * Faculty (their own sections' rosters), the Registrar Head (every grade,
 * as keeper of the official record), and Registrar Staff (every grade too —
 * PRD §3.8 "view permitted academic records", widened in Phase 7b Task 3).
 * "Which rows" is resolved by `AcademicGrade::scopeVisibleTo` — this Policy
 * is the role-level gate.
 *
 * `update` and `submit` share one ownership check (own-section, draft-only
 * content changes and the Faculty's own draft→submitted transition); `lock`
 * is a distinct, later checkpoint reserved for the Registrar Head, the same
 * two-checkpoint split `EnrollmentPolicy` uses for `decideApproval`/`void`.
 */
final class AcademicGradePolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            UserRole::Student,
            UserRole::Faculty,
            UserRole::RegistrarHead,
            UserRole::RegistrarStaff,
        ], true);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Faculty;
    }

    public function update(User $user, AcademicGrade $grade): bool
    {
        return $user->role === UserRole::Faculty && $grade->section?->professor_id === $user->id;
    }

    public function submit(User $user, AcademicGrade $grade): bool
    {
        return $this->update($user, $grade);
    }

    public function lock(User $user, AcademicGrade $grade): bool
    {
        return $user->role === UserRole::RegistrarHead;
    }

    public function lockAll(User $user): bool
    {
        return $user->role === UserRole::RegistrarHead;
    }
}
