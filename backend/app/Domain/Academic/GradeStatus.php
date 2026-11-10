<?php

namespace App\Domain\Academic;

/**
 * The grade lifecycle defined in PRD §4.3: draft → submitted → locked.
 *
 * Specified by the PRD, so this enum is authoritative rather than provisional.
 * Note that the *passing-grade rule* is a separate matter and remains an open
 * §17 decision — this enum describes only who may still edit a grade record,
 * never whether a grade passes.
 */
enum GradeStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Locked = 'locked';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::Locked => 'Locked',
        };
    }

    /**
     * PRD §4.3: "Professors may edit only draft grades for their assigned
     * sections." Corrections after locking require a separate authorized,
     * audited workflow that this slice does not implement.
     */
    public function isEditableByEncoder(): bool
    {
        return $this === self::Draft;
    }
}
