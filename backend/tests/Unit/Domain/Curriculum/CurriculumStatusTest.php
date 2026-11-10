<?php

namespace Tests\Unit\Domain\Curriculum;

use App\Domain\Curriculum\CurriculumStatus;
use PHPUnit\Framework\TestCase;

final class CurriculumStatusTest extends TestCase
{
    public function test_status_values_are_the_five_provisional_cases(): void
    {
        self::assertSame(
            ['draft', 'pending_dean_review', 'pending_executive_review', 'active', 'archived'],
            array_column(CurriculumStatus::cases(), 'value'),
        );
    }

    public function test_labels_are_stable_and_human_readable(): void
    {
        self::assertSame('Draft', CurriculumStatus::Draft->label());
        self::assertSame('Active', CurriculumStatus::Active->label());
        self::assertSame('Archived', CurriculumStatus::Archived->label());
    }

    public function test_draft_is_not_visible_to_learners_but_active_and_archived_are(): void
    {
        self::assertFalse(CurriculumStatus::Draft->isVisibleToLearners());
        self::assertTrue(CurriculumStatus::Active->isVisibleToLearners());
        self::assertTrue(CurriculumStatus::Archived->isVisibleToLearners());
    }

    public function test_pending_dean_review_has_the_expected_value_and_label(): void
    {
        self::assertSame('pending_dean_review', CurriculumStatus::PendingDeanReview->value);
        self::assertSame('Pending Dean Review', CurriculumStatus::PendingDeanReview->label());
        self::assertFalse(CurriculumStatus::PendingDeanReview->isVisibleToLearners());
    }

    public function test_pending_executive_review_has_the_expected_value_and_label(): void
    {
        self::assertSame('pending_executive_review', CurriculumStatus::PendingExecutiveReview->value);
        self::assertSame('Pending Executive Review', CurriculumStatus::PendingExecutiveReview->label());
        self::assertFalse(CurriculumStatus::PendingExecutiveReview->isVisibleToLearners());
    }
}
