<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\StudentProfile;
use App\Models\User;

/**
 * Gates the prospectus and grade-slip endpoints — a student's full academic
 * history, not a single grade record (`AcademicGradePolicy` already governs
 * those). A Student may view only their own; the Registrar Head and
 * Registrar Staff may view any student's, matching their existing broad
 * `AcademicGrade::scopeVisibleTo` access. Faculty are deliberately excluded
 * even though they can view individual grades for their own sections —
 * a full cross-term academic history is not a professor's business.
 */
final class AcademicRecordPolicy
{
    public function view(User $user, StudentProfile $student): bool
    {
        if ($user->role === UserRole::Student) {
            return $student->user_id === $user->id;
        }

        return in_array($user->role, [
            UserRole::RegistrarHead,
            UserRole::RegistrarStaff,
            UserRole::ProgramChair,
            UserRole::Dean,
            UserRole::ExecutiveDirector,
        ], true);
    }

    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            UserRole::RegistrarHead,
            UserRole::RegistrarStaff,
            UserRole::ProgramChair,
            UserRole::Dean,
            UserRole::ExecutiveDirector,
        ], true);
    }
}
