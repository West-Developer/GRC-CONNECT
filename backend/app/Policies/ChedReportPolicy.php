<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\User;

/**
 * The CHED / NSTP reports carry a named student list with birth dates and contact details, so only
 * the Registrar Head sees or downloads them (ADR 0049). The route also carries `role:registrar_head`;
 * this is the second check, and it is the one a Super Admin's acting context passes through.
 */
final class ChedReportPolicy
{
    public function view(User $user): bool
    {
        return $user->role === UserRole::RegistrarHead;
    }
}
