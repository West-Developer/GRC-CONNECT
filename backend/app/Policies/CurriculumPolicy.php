<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\User;

/**
 * Read access follows the same shape as ProgramPolicy/AcademicTermPolicy.
 * `create`/`update` are Program-Chair-only and college-scoped. The three new
 * abilities below back the approval chain's
 * transition endpoint (CurriculumController::transition): `submit` adds a
 * real college check on top of the role check (see its own docblock) since
 * it starts a chain other people rely on, so the acting Chair must own the
 * curriculum's college. Dean review is also college-scoped: a Dean may only
 * act on curricula whose program belongs to the Dean's assigned college.
 * Executive Director review remains institution-wide.
 */
final class CurriculumPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Curriculum $curriculum): bool
    {
        if (! $user->role->isLearnerScoped()) {
            return true;
        }

        return $curriculum->status->isVisibleToLearners();
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::ProgramChair && $user->college !== null;
    }

    public function createForProgram(User $user, Program $program): bool
    {
        return $this->create($user) && $program->college === $user->college;
    }

    public function update(User $user, Curriculum $curriculum): bool
    {
        return $this->create($user) && $curriculum->program->college === $user->college;
    }

    /**
     * Submission is the first step of a chain other people rely on (Dean
     * review and notifications routed to the submitter), so it confirms the
     * curriculum belongs to the acting Chair's college.
     */
    public function submit(User $user, Curriculum $curriculum): bool
    {
        if ($user->role !== UserRole::ProgramChair) {
            return false;
        }

        return $user->college !== null && $curriculum->program->college === $user->college;
    }

    public function approveAsDean(User $user, Curriculum $curriculum): bool
    {
        return $user->role === UserRole::Dean
            && $user->college !== null
            && $curriculum->program->college === $user->college;
    }

    public function approveAsExecutive(User $user): bool
    {
        return $user->role === UserRole::ExecutiveDirector;
    }
}
