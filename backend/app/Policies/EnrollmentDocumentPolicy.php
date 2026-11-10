<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\EnrollmentDocument;
use App\Models\User;

/**
 * The owning Student, Accounting Staff, Registrar Head, and Registrar Staff
 * may read generated enrollment documents (the COR). "Which rows" is resolved by
 * `EnrollmentDocument::scopeVisibleTo` — this Policy is the role-level gate,
 * matching `EnrollmentPolicy`'s division of labor.
 */
final class EnrollmentDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            UserRole::Student,
            UserRole::AccountingStaff,
            UserRole::RegistrarHead,
            UserRole::RegistrarStaff,
        ], true);
    }

    public function view(User $user, EnrollmentDocument $document): bool
    {
        return EnrollmentDocument::query()
            ->visibleTo($user)
            ->whereKey($document->id)
            ->exists();
    }
}
