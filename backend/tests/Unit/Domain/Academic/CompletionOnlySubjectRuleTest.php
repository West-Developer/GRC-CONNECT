<?php

namespace Tests\Unit\Domain\Academic;

use App\Domain\Academic\CompletionOnlySubjectRule;
use App\Domain\Academic\GradeMark;
use PHPUnit\Framework\TestCase;

final class CompletionOnlySubjectRuleTest extends TestCase
{
    private const PREFIXES = ['LEAD'];

    public function test_normalize_code_strips_spaces_and_uppercases(): void
    {
        self::assertSame('LEAD1', CompletionOnlySubjectRule::normalizeCode('LEAD 1'));
        self::assertSame('LEAD1', CompletionOnlySubjectRule::normalizeCode('lead-1'));
        self::assertSame('LEAD1', CompletionOnlySubjectRule::normalizeCode('LEAD1'));
        self::assertSame('LEAD1', CompletionOnlySubjectRule::normalizeCode(' Lead_1 '));
    }

    /**
     * Every spelling variant actually seeded by GrcSubjectCatalogSeeder for
     * the 8 Leadership subjects — the exact real-data inconsistency this
     * rule exists to absorb.
     */
    public function test_matches_every_observed_lead_code_spelling(): void
    {
        $observedCodes = ['LEAD 1', 'LEAD2', 'LEAD 3', 'LEAD4', 'LEAD 5', 'LEAD6', 'LEAD 7', 'LEAD8'];

        foreach ($observedCodes as $code) {
            self::assertTrue(
                CompletionOnlySubjectRule::matches($code, self::PREFIXES),
                "Expected \"{$code}\" to match the LEAD completion-only prefix.",
            );
        }
    }

    public function test_matches_a_future_double_digit_lead_code(): void
    {
        self::assertTrue(CompletionOnlySubjectRule::matches('LEAD 10', self::PREFIXES));
        self::assertTrue(CompletionOnlySubjectRule::matches('LEAD10', self::PREFIXES));
    }

    public function test_does_not_match_an_unrelated_code_sharing_the_prefix_letters(): void
    {
        self::assertFalse(CompletionOnlySubjectRule::matches('LEADERSHIP-ELECTIVE', self::PREFIXES));
        self::assertFalse(CompletionOnlySubjectRule::matches('LEADCOMM', self::PREFIXES));
    }

    public function test_does_not_match_an_ordinary_academic_subject(): void
    {
        self::assertFalse(CompletionOnlySubjectRule::matches('CS101', self::PREFIXES));
        self::assertFalse(CompletionOnlySubjectRule::matches('PROG1L', self::PREFIXES));
    }

    public function test_empty_prefix_list_matches_nothing(): void
    {
        self::assertFalse(CompletionOnlySubjectRule::matches('LEAD 1', []));
    }

    public function test_allowed_marks_for_a_completion_only_subject_is_complete_or_incomplete_only(): void
    {
        $allowed = CompletionOnlySubjectRule::allowedMarks('LEAD 1', self::PREFIXES);

        self::assertSame(['C', 'INC'], array_column($allowed, 'value'));
    }

    public function test_allowed_marks_for_an_academic_subject_is_numeric_plus_inc_never_drp_c_or_nc(): void
    {
        $allowed = CompletionOnlySubjectRule::allowedMarks('CS101', self::PREFIXES);
        $values = array_column($allowed, 'value');

        self::assertContains('1.00', $values);
        self::assertContains('5.00', $values);
        self::assertContains('INC', $values);
        self::assertNotContains('DRP', $values);
        self::assertNotContains('C', $values);
        self::assertNotContains('NC', $values);
        self::assertCount(11, $allowed);
    }

    public function test_allowed_marks_come_from_grade_mark_case_sets(): void
    {
        foreach (CompletionOnlySubjectRule::allowedMarks('LEAD 1', self::PREFIXES) as $mark) {
            self::assertInstanceOf(GradeMark::class, $mark);
        }
    }
}
