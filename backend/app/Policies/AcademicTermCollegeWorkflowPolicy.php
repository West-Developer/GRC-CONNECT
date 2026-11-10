<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\AcademicTermCollegeWorkflow;
use App\Models\User;

final class AcademicTermCollegeWorkflowPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::RegistrarHead, UserRole::ProgramChair], true);
    }

    public function view(User $user, AcademicTermCollegeWorkflow $workflow): bool
    {
        if ($user->role === UserRole::RegistrarHead) {
            return true;
        }

        return $user->role === UserRole::ProgramChair
            && $user->college !== null
            && $user->college === $workflow->college;
    }

    public function update(User $user, AcademicTermCollegeWorkflow $workflow): bool
    {
        return $user->role === UserRole::ProgramChair
            && $user->college !== null
            && $user->college === $workflow->college;
    }
}
