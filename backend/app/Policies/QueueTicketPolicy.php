<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\User;

/**
 * PRD §5.3 FR-FIN-006: Accounting Staff alone maintains the payment queue.
 * Unlike `EnrollmentPolicy`/`AcademicGradePolicy`, both transitions
 * (`serve`, `complete`) are gated to the same single role with no
 * per-ticket ownership dimension, so one `update` ability covers both —
 * there is no ADR 0011 multi-role split to make here.
 */
final class QueueTicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::AccountingStaff;
    }

    public function update(User $user): bool
    {
        return $user->role === UserRole::AccountingStaff;
    }
}
