<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\ScheduleProposal;
use App\Models\User;

/**
 * Unlike every other write-gated resource in this API, a single
 * `PATCH /schedule-proposals/{scheduleProposal}` route serves multiple roles
 * depending on the requested transition — so there is no single `role:`
 * middleware to gate the route. Instead this Policy exposes one ability per
 * transition family; the controller resolves which ability applies from the
 * request's `action` field before checking it. See ADR 0011.
 */
final class ScheduleProposalPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ScheduleProposal $proposal): bool
    {
        if ($user->role === UserRole::ProgramChair) {
            return $proposal->college === $user->college?->value;
        }

        if (! $user->role->isLearnerScoped()) {
            return true;
        }

        return $proposal->status->isVisibleToLearners();
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::ProgramChair;
    }

    /**
     * Covers `dean_approve` and `dean_return` — both are the Dean's own
     * decision at their checkpoint (approve forward, or recall their own
     * pending approval back to draft).
     */
    public function approveAsDean(User $user): bool
    {
        return $user->role === UserRole::Dean;
    }

    /**
     * Covers `executive_return` only — the Executive Director's forward
     * path is `publish` (its own ability below), not a separate approval
     * step; see `ScheduleProposalStatus`'s docblock for the lifecycle
     * amendment this reflects.
     */
    public function approveAsExecutive(User $user): bool
    {
        return $user->role === UserRole::ExecutiveDirector;
    }

    public function publish(User $user): bool
    {
        return $user->role === UserRole::ExecutiveDirector;
    }

    public function close(User $user): bool
    {
        return $user->role === UserRole::RegistrarHead;
    }
}
