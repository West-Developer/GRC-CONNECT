<?php

namespace Tests\Unit\Domain\Enrollment;

use App\Domain\Enrollment\BlockSectionAccessPolicy;
use App\Domain\Enrollment\EnrollmentAccessContext;
use App\Domain\Enrollment\EnrollmentAudience;
use PHPUnit\Framework\TestCase;

/**
 * The full block-access matrix. No database — `BlockSectionAccessPolicy` is
 * pure by design so the rule stays verifiable independently of migrations.
 */
final class BlockSectionAccessPolicyTest extends TestCase
{
    private function context(
        EnrollmentAudience $viewer,
        bool $viewerOpen = true,
        bool $irregularOpen = false,
    ): EnrollmentAccessContext {
        $open = [];
        if ($viewerOpen) {
            $open[] = $viewer;
        }
        if ($irregularOpen && ! in_array(EnrollmentAudience::Irregular, $open, true)) {
            $open[] = EnrollmentAudience::Irregular;
        }

        return new EnrollmentAccessContext($viewer, $viewerOpen, $irregularOpen, $open);
    }

    public function test_a_non_block_section_is_open_to_everyone(): void
    {
        foreach (EnrollmentAudience::cases() as $audience) {
            self::assertTrue(
                BlockSectionAccessPolicy::allows(false, 2, $this->context($audience)),
                "{$audience->value} should reach a non-block section",
            );
        }
    }

    public function test_a_null_block_flag_is_treated_as_unrestricted(): void
    {
        // Legacy rows generated before block flagging existed carry NULL;
        // withholding them would hide real sections from real students.
        foreach (EnrollmentAudience::cases() as $audience) {
            self::assertTrue(BlockSectionAccessPolicy::allows(null, 2, $this->context($audience)));
        }
    }

    public function test_a_regular_student_reaches_only_their_own_year_block(): void
    {
        $context = $this->context(EnrollmentAudience::Year2);

        self::assertTrue(BlockSectionAccessPolicy::allows(true, 2, $context));
        self::assertFalse(BlockSectionAccessPolicy::allows(true, 1, $context));
        self::assertFalse(BlockSectionAccessPolicy::allows(true, 3, $context));
        self::assertFalse(BlockSectionAccessPolicy::allows(true, 4, $context));
    }

    public function test_every_year_level_reaches_its_matching_block(): void
    {
        foreach ([1 => EnrollmentAudience::Year1, 2 => EnrollmentAudience::Year2, 3 => EnrollmentAudience::Year3, 4 => EnrollmentAudience::Year4] as $yearLevel => $audience) {
            self::assertTrue(
                BlockSectionAccessPolicy::allows(true, $yearLevel, $this->context($audience)),
                "{$audience->value} should reach its own year {$yearLevel} block",
            );
        }
    }

    public function test_an_irregular_student_is_withheld_from_block_seats_before_their_window_opens(): void
    {
        $context = $this->context(EnrollmentAudience::Irregular, viewerOpen: false, irregularOpen: false);

        foreach ([1, 2, 3, 4] as $yearLevel) {
            self::assertFalse(BlockSectionAccessPolicy::allows(true, $yearLevel, $context));
        }
    }

    public function test_an_irregular_student_reaches_any_block_once_the_irregular_window_opens(): void
    {
        $context = $this->context(EnrollmentAudience::Irregular, viewerOpen: true, irregularOpen: true);

        foreach ([1, 2, 3, 4] as $yearLevel) {
            self::assertTrue(
                BlockSectionAccessPolicy::allows(true, $yearLevel, $context),
                "an irregular student should reach the year {$yearLevel} block during their window",
            );
        }
    }

    public function test_an_unknown_block_year_is_never_silently_withheld(): void
    {
        self::assertTrue(BlockSectionAccessPolicy::allows(true, null, $this->context(EnrollmentAudience::Year3)));
    }

    public function test_reason_codes_distinguish_the_two_ways_a_block_can_be_withheld(): void
    {
        self::assertNull(BlockSectionAccessPolicy::reasonFor(true, 2, $this->context(EnrollmentAudience::Year2)));

        self::assertSame(
            'block_other_year',
            BlockSectionAccessPolicy::reasonFor(true, 3, $this->context(EnrollmentAudience::Year2)),
        );

        self::assertSame(
            'block_restricted',
            BlockSectionAccessPolicy::reasonFor(true, 3, $this->context(EnrollmentAudience::Irregular, viewerOpen: false)),
        );
    }
}
