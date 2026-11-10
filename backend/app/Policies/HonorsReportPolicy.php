<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\User;

final class HonorsReportPolicy
{
    public function view(User $user): bool
    {
        return $user->role === UserRole::Dean;
    }
}
