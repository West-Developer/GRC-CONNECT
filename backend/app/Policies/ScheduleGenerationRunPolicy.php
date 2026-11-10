<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\AcademicTerm;
use App\Models\ScheduleGenerationRun;
use App\Models\User;

final class ScheduleGenerationRunPolicy
{
    public function create(User $user, AcademicTerm $academicTerm): bool
    {
        return $user->role === UserRole::ProgramChair
            && $academicTerm->isActionableCurrent();
    }

    public function view(User $user, ScheduleGenerationRun $run): bool
    {
        return $user->role === UserRole::ProgramChair
            && $user->college?->value === $run->college;
    }
}
