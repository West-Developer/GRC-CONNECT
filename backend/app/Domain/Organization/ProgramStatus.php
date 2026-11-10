<?php

namespace App\Domain\Organization;

/**
 * PROVISIONAL VOCABULARY — NOT AN APPROVED INSTITUTIONAL POLICY VALUE.
 *
 * PRD §17 lists institutional status vocabularies as an open decision
 * requiring GRC approval. These two values exist only so this slice has
 * something concrete to authorize against; they must be replaced with the
 * confirmed vocabulary via a data migration before any production-like
 * deployment. The `programs.status` column stays a plain string for exactly
 * this reason.
 */
enum ProgramStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
        };
    }

    /**
     * Whether learner-scoped roles (student, faculty, accounting staff) may
     * see this program. Planning roles always see every program regardless
     * of this value — see Program::scopeVisibleTo().
     */
    public function isVisibleToLearners(): bool
    {
        return match ($this) {
            self::Active => true,
            self::Inactive => false,
        };
    }
}
