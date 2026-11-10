<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\NoCreditDeclaration;
use App\Models\User;

/**
 * "No subject to credit" (ADR 0026 / 0050, amended): a Student declares it for themselves, and the Program
 * Chair of the student's college confirms it. Nobody else reads or changes it: it is part of the student's
 * credit mapping, which the Registrar Head never has to approve when nothing was claimed.
 */
final class NoCreditDeclarationPolicy
{
    /** A Student declares, and reads, their own (the Action pins the student to the caller). */
    public function declare(User $user): bool
    {
        return $user->role === UserRole::Student;
    }

    /** The Program Chair's list of declarations waiting for review. */
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::ProgramChair;
    }

    /**
     * Only for a student of the Chair's own college; a Chair without an assigned college is unscoped, the same
     * fallback `TransfereeCreditPolicy::manageStudent` uses.
     */
    public function confirm(User $user, NoCreditDeclaration $declaration): bool
    {
        if ($user->role !== UserRole::ProgramChair) {
            return false;
        }

        if ($user->college === null) {
            return true;
        }

        $declaration->loadMissing('student.program');

        return $declaration->student->program->college === $user->college;
    }
}
