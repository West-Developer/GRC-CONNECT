<?php

namespace App\Domain\Curriculum;

/**
 * PROVISIONAL VOCABULARY — NOT AN APPROVED INSTITUTIONAL POLICY VALUE.
 *
 * PRD §17 lists institutional status vocabularies as an open decision
 * requiring GRC approval. These two values exist only so this slice has
 * something concrete to authorize against; they must be replaced with the
 * confirmed vocabulary via a data migration before any production-like
 * deployment. The `subjects.status` column stays a plain string for exactly
 * this reason.
 */
enum SubjectStatus: string
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
     * see this subject. Planning roles always see every subject regardless
     * of this value — see Subject::scopeVisibleTo().
     */
    public function isVisibleToLearners(): bool
    {
        return match ($this) {
            self::Active => true,
            self::Inactive => false,
        };
    }
}
