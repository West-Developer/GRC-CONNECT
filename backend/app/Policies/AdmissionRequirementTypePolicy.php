<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\User;

/**
 * The Admission checklist catalogue is the Admission Staff's to extend
 * (stakeholder Doc 14, ADR 0037). Class-level: there is no per-row rule.
 */
final class AdmissionRequirementTypePolicy
{
    /** The Create Account checklist lists the catalogue before a student exists. */
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::AdmissionStaff;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::AdmissionStaff;
    }
}
