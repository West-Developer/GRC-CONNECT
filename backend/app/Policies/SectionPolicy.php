<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Domain\Organization\SectionPlanStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\Section;
use App\Models\User;

/**
 * Same shape as CurriculumPolicy: read access follows the learner/planner
 * visibility split; write access is restricted to the Program Chair role,
 * matching curriculum authorship (sections are the chair's schedule plan).
 */
final class SectionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Section $section): bool
    {
        if ($user->role === UserRole::ProgramChair && $user->college !== null && $section->section_plan_id !== null) {
            return $section->sectionPlan?->college === $user->college->value;
        }

        if (! $user->role->isLearnerScoped()) {
            if ($user->role === UserRole::ExecutiveDirector) {
                return $section->status === SectionStatus::Published;
            }

            return true;
        }

        if ($user->role === UserRole::Faculty) {
            return $section->professor_id === $user->id
                && $section->status->isVisibleToLearners();
        }

        return $section->status->isVisibleToLearners();
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::ProgramChair && $user->college !== null;
    }

    public function viewGradeSubmission(User $user): bool
    {
        return $user->role === UserRole::Faculty;
    }

    public function viewGrades(User $user, Section $section): bool
    {
        return $user->role === UserRole::Faculty && $section->professor_id === $user->id;
    }

    public function updateGrades(User $user, Section $section): bool
    {
        return $this->viewGrades($user, $section);
    }

    public function submitGrades(User $user, Section $section): bool
    {
        return $this->viewGrades($user, $section);
    }

    /**
     * The Dean may assign or unassign the professor of a section their college
     * planned (ADR 0033). Only the professor; nothing else about the section.
     */
    public function assignProfessor(User $user, Section $section): bool
    {
        return $user->role === UserRole::Dean
            && $user->college !== null
            && $section->section_plan_id !== null
            && $section->sectionPlan?->college === $user->college->value
            && ! in_array($section->status, [SectionStatus::Closed, SectionStatus::Cancelled], true);
    }

    /**
     * A published section is final for the Program Head (ADR 0032); a change
     * request is how they ask the Registrar Head to alter it.
     */
    public function requestChange(User $user, Section $section): bool
    {
        return $user->role === UserRole::ProgramChair
            && $section->status === SectionStatus::Published
            && ($user->college === null || $section->section_plan_id === null || $section->sectionPlan?->college === $user->college->value);
    }

    public function update(User $user, Section $section): bool
    {
        return $user->role === UserRole::ProgramChair
            && ($section->sectionPlan === null || $section->sectionPlan->status !== SectionPlanStatus::Submitted)
            && ($user->college === null || $section->section_plan_id === null || $section->sectionPlan?->college === $user->college->value);
    }
}
