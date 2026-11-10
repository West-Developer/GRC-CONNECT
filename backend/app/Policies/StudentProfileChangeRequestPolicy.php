<?php

namespace App\Policies;

use App\Domain\Identity\StudentProfileChangeRequestStatus;
use App\Domain\Identity\UserRole;
use App\Models\StudentProfileChangeRequest;
use App\Models\User;

final class StudentProfileChangeRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::Student, UserRole::AdmissionStaff], true);
    }

    public function view(User $user, StudentProfileChangeRequest $changeRequest): bool
    {
        return $user->role === UserRole::AdmissionStaff
            || ($user->role === UserRole::Student && $changeRequest->student->user_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Student;
    }

    public function update(User $user, StudentProfileChangeRequest $changeRequest): bool
    {
        return $user->role === UserRole::Student
            && $changeRequest->student->user_id === $user->id
            && $changeRequest->status === StudentProfileChangeRequestStatus::Pending;
    }

    public function cancel(User $user, StudentProfileChangeRequest $changeRequest): bool
    {
        return $this->update($user, $changeRequest);
    }

    public function decide(User $user, StudentProfileChangeRequest $changeRequest): bool
    {
        return $user->role === UserRole::AdmissionStaff;
    }
}
