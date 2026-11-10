<?php

namespace Tests\Unit\Domain\Academic;

use App\Domain\Academic\GradeStatus;
use PHPUnit\Framework\TestCase;

final class GradeStatusTest extends TestCase
{
    public function test_status_values_are_the_prd_lifecycle(): void
    {
        self::assertSame(
            ['draft', 'submitted', 'locked'],
            array_column(GradeStatus::cases(), 'value'),
        );
    }

    public function test_labels_are_stable_and_human_readable(): void
    {
        self::assertSame('Draft', GradeStatus::Draft->label());
        self::assertSame('Submitted', GradeStatus::Submitted->label());
        self::assertSame('Locked', GradeStatus::Locked->label());
    }

    public function test_only_draft_is_editable_by_the_encoder(): void
    {
        self::assertTrue(GradeStatus::Draft->isEditableByEncoder());
        self::assertFalse(GradeStatus::Submitted->isEditableByEncoder());
        self::assertFalse(GradeStatus::Locked->isEditableByEncoder());
    }
}
