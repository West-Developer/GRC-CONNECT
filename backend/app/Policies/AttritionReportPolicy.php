<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\User;

final class AttritionReportPolicy
{
    public function view(User $user): bool
    {
        return $user->role === UserRole::RegistrarHead;
    }
}
