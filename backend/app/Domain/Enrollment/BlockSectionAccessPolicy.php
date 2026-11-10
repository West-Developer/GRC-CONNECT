<?php

namespace App\Domain\Enrollment;

/**
 * FR-ENR-011, as a pure function: may this student take a seat in this
 * section right now?
 *
 * Block-exclusivity is time-based. During a year level's own window its
 * blocks are reserved for that year's regular students; once the irregular
 * window opens, an irregular student may pick freely from any section that
 * still has seats, block sections included. Reserving block seats forever
 * would leave irregular students with nothing to enrol in at all.
 *
 * Nothing here touches the database, so the whole matrix is unit-testable
 * (`BlockSectionAccessPolicyTest`) regardless of local database state.
 */
final class BlockSectionAccessPolicy
{
    /**
     * @param  ?bool  $isBlockExclusive  `sections.is_block_exclusive`; null means unrestricted
     * @param  ?int  $sectionBlockYearLevel  the year level of the section's block, when known
     */
    public static function allows(
        ?bool $isBlockExclusive,
        ?int $sectionBlockYearLevel,
        EnrollmentAccessContext $context,
    ): bool {
        // Not a block seat (including the legacy null rows) — open to all.
        if ($isBlockExclusive !== true) {
            return true;
        }

        if ($context->viewerAudience === EnrollmentAudience::Irregular) {
            return $context->irregularWindowIsOpen;
        }

        // Never silently hide a section whose block year cannot be
        // determined; let the ordinary seat and prerequisite rules decide.
        if ($sectionBlockYearLevel === null) {
            return true;
        }

        return $sectionBlockYearLevel === $context->viewerAudience->yearLevel();
    }

    /**
     * Why a section was withheld, for the student-facing reason list.
     * Returns null when the section is allowed.
     */
    public static function reasonFor(
        ?bool $isBlockExclusive,
        ?int $sectionBlockYearLevel,
        EnrollmentAccessContext $context,
    ): ?string {
        if (self::allows($isBlockExclusive, $sectionBlockYearLevel, $context)) {
            return null;
        }

        if ($context->viewerAudience === EnrollmentAudience::Irregular) {
            return 'block_restricted';
        }

        return 'block_other_year';
    }
}
