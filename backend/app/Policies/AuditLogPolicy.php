<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\AuditLog;
use App\Models\User;

final class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::RegistrarHead, UserRole::SuperAdmin], true);
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return in_array($user->role, [UserRole::RegistrarHead, UserRole::SuperAdmin], true);
    }
}
