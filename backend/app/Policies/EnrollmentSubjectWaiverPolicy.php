<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\User;

/**
 * Prerequisite waivers are the Registrar Head's alone (stakeholder Doc 14,
 * ADR 0031): granting one overrides a curriculum rule for one student, so it
 * is the same "authorized edge case" authority PRD §3.7 gives that role.
 * Class-level: every ability applies to any waiver.
 */
final class EnrollmentSubjectWaiverPolicy
{
    public function manage(User $user): bool
    {
        return $user->role === UserRole::RegistrarHead;
    }
}
