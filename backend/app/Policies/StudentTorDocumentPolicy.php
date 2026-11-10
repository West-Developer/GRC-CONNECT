<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\StudentTorDocument;
use App\Models\User;

/**
 * A Transcript of Records is personal academic data. A Student uploads, sees
 * and removes only their own; the Program Chair (their college's students) and
 * Registrar Staff / Head read it to map and approve credits. Nobody else.
 */
final class StudentTorDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            UserRole::Student,
            UserRole::ProgramChair,
            UserRole::RegistrarStaff,
            UserRole::RegistrarHead,
        ], true);
    }

    public function view(User $user, StudentTorDocument $document): bool
    {
        if (in_array($user->role, [UserRole::RegistrarStaff, UserRole::RegistrarHead], true)) {
            return true;
        }

        $document->loadMissing('student.program');

        if ($user->role === UserRole::Student) {
            return $document->student->user_id === $user->id;
        }

        if ($user->role === UserRole::ProgramChair) {
            return $user->college === null || $document->student->program->college === $user->college;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Student;
    }

    public function delete(User $user, StudentTorDocument $document): bool
    {
        if ($user->role !== UserRole::Student) {
            return false;
        }

        $document->loadMissing('student');

        return $document->student->user_id === $user->id;
    }
}
