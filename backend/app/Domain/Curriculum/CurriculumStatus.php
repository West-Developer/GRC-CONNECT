<?php

namespace App\Domain\Curriculum;

/**
 * PROVISIONAL VOCABULARY — NOT AN APPROVED INSTITUTIONAL POLICY VALUE.
 *
 * PRD §17 lists institutional status vocabularies as an open decision
 * requiring GRC approval. These values exist only so this slice has
 * something concrete to authorize against; they must be replaced with the
 * confirmed vocabulary via a data migration before any production-like
 * deployment. The `curricula.status` column stays a plain string for exactly
 * this reason.
 *
 * `PendingDeanReview` and `PendingExecutiveReview` are the two checkpoints
 * of the approval chain a Program Chair's `Draft` curriculum passes through
 * before becoming `Active` — see `CurriculumTransitionRules`. Neither is
 * reachable or leavable except through that class's transitions.
 */
enum CurriculumStatus: string
{
    case Draft = 'draft';
    case PendingDeanReview = 'pending_dean_review';
    case PendingExecutiveReview = 'pending_executive_review';
    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingDeanReview => 'Pending Dean Review',
            self::PendingExecutiveReview => 'Pending Executive Review',
            self::Active => 'Active',
            self::Archived => 'Archived',
        };
    }

    /**
     * Whether learner-scoped roles (student, faculty, accounting staff) may
     * see this curriculum. A curriculum still being authored — or under
     * review — is not yet learner-facing; active and archived curricula
     * are (students already following an archived curriculum still need to
     * see it). Planning roles always see every curriculum regardless of
     * this value — see Curriculum::scopeVisibleTo().
     */
    public function isVisibleToLearners(): bool
    {
        return match ($this) {
            self::Draft, self::PendingDeanReview, self::PendingExecutiveReview => false,
            self::Active, self::Archived => true,
        };
    }
}
