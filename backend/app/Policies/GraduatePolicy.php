<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\User;

final class GraduatePolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            UserRole::RegistrarHead,
            UserRole::RegistrarStaff,
            UserRole::Dean,
            UserRole::ExecutiveDirector,
            UserRole::ItAdmin,
        ], true);
    }
}
