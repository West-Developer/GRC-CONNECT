<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\QueueKioskCredential;
use App\Models\User;

final class QueueKioskCredentialPolicy
{
    public function view(User $user, QueueKioskCredential $credential): bool
    {
        return $user->role === UserRole::AccountingStaff;
    }

    public function update(User $user, QueueKioskCredential $credential): bool
    {
        return $user->role === UserRole::AccountingStaff;
    }
}
