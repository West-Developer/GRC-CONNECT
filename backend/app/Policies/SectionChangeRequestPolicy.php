<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Domain\Scheduling\SectionChangeRequestStatus;
use App\Models\SectionChangeRequest;
use App\Models\User;

/**
 * Change requests on published sections (ADR 0032): the Program Head files and
 * may withdraw their own pending request; the Registrar Head decides.
 */
final class SectionChangeRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::ProgramChair, UserRole::RegistrarHead], true);
    }

    public function decide(User $user, SectionChangeRequest $request): bool
    {
        return $user->role === UserRole::RegistrarHead;
    }

    public function cancel(User $user, SectionChangeRequest $request): bool
    {
        return $user->role === UserRole::ProgramChair
            && $request->requested_by === $user->id
            && $request->status === SectionChangeRequestStatus::Pending;
    }
}
