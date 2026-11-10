<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\StudentProfile;
use App\Models\User;

/**
 * A Student may view only their own record. Admission Staff is the one
 * broader role-based reader/writer here, per PRD §3.2's Student Records
 * directory: it may list, view, and directly correct any profile. No other
 * staff role gets a general student-profile read through this endpoint —
 * Accounting Staff's narrower `viewAccount`/`recordAccountPayment`
 * abilities below are the only exception, scoped to the payment desk.
 */
final class StudentProfilePolicy
{
    public function view(User $user, StudentProfile $profile): bool
    {
        return $user->id === $profile->user_id
            || $user->role === UserRole::AdmissionStaff;
    }

    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::AdmissionStaff;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::AdmissionStaff;
    }

    public function update(User $user, StudentProfile $profile): bool
    {
        return $user->role === UserRole::AdmissionStaff;
    }

    /**
     * This narrower account-summary ability does not widen the ordinary
     * student-profile read endpoint: a Student may read only their own
     * account, while Accounting Staff may read account context at the
     * payment desk.
     */
    public function viewAccount(User $user, StudentProfile $profile): bool
    {
        return ($user->role === UserRole::Student && $user->id === $profile->user_id)
            || $user->role === UserRole::AccountingStaff;
    }

    /**
     * The Registrar Head's read of one student's profile while reviewing an
     * enrollment (stakeholder Doc 14). Like `viewAccount`, this is a narrow
     * ability: it does not open the general student-profile directory, which
     * stays with Admission Staff.
     */
    public function viewForRegistrar(User $user, StudentProfile $profile): bool
    {
        return $user->role === UserRole::RegistrarHead;
    }

    /**
     * The Admission requirements checklist (ADR 0037): a Student reads their
     * own, Admission Staff read any.
     */
    public function viewAdmissionRequirements(User $user, StudentProfile $profile): bool
    {
        return ($user->role === UserRole::Student && $user->id === $profile->user_id)
            || $user->role === UserRole::AdmissionStaff;
    }

    /**
     * Ticking requirements off is the Admission Staff's alone.
     */
    public function manageAdmissionRequirements(User $user, StudentProfile $profile): bool
    {
        return $user->role === UserRole::AdmissionStaff;
    }

    public function recordAccountPayment(User $user, StudentProfile $profile): bool
    {
        return $user->role === UserRole::AccountingStaff;
    }

    /**
     * The Cashier confirms a freshman's first payment, and previews what it will do
     * (ADR 0043). Accounting Staff only.
     */
    public function confirmFreshmanPayment(User $user, StudentProfile $profile): bool
    {
        return $user->role === UserRole::AccountingStaff;
    }

    /**
     * The read-only list of every student: Registrar Staff and the Registrar Head.
     * A class-level ability: it lists many profiles.
     */
    public function viewRegistrarStudents(User $user): bool
    {
        return in_array($user->role, [UserRole::RegistrarStaff, UserRole::RegistrarHead], true);
    }

    /**
     * The Cashier's general student search (Advance Payment). A class-level
     * ability: there is no single profile to authorize against yet.
     */
    public function searchForCashier(User $user): bool
    {
        return $user->role === UserRole::AccountingStaff;
    }
}
