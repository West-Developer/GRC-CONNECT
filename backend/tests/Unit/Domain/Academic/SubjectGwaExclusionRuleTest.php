<?php

namespace Tests\Unit\Domain\Academic;

use App\Domain\Academic\SubjectGwaExclusionRule;
use PHPUnit\Framework\TestCase;

final class SubjectGwaExclusionRuleTest extends TestCase
{
    public function test_it_excludes_normalized_nstp_pathfit_and_pe_codes(): void
    {
        self::assertFalse(SubjectGwaExclusionRule::countsTowardGwa(' NSTP 1 '));
        self::assertFalse(SubjectGwaExclusionRule::countsTowardGwa('pathfit-3'));
        self::assertFalse(SubjectGwaExclusionRule::countsTowardGwa('PE 4'));
        self::assertTrue(SubjectGwaExclusionRule::countsTowardGwa('RIZAL'));
    }
}
