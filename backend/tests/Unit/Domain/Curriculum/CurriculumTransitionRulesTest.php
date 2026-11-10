<?php

namespace Tests\Unit\Domain\Curriculum;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\CurriculumTransitionRules;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurriculumTransitionRulesTest extends TestCase
{
    #[DataProvider('checkpointActions')]
    public function test_checkpoint_actions_use_the_status_the_reviewer_actually_receives(
        string $action,
        CurriculumStatus $required,
        CurriculumStatus $target,
        bool $isReturn,
    ): void {
        self::assertSame($required, CurriculumTransitionRules::requiredStatus($action));
        self::assertSame($target, CurriculumTransitionRules::targetStatus($action));
        self::assertSame($isReturn, CurriculumTransitionRules::isReturn($action));
    }

    /** @return array<string, array{string, CurriculumStatus, CurriculumStatus, bool}> */
    public static function checkpointActions(): array
    {
        return [
            'Chair submits a draft' => ['submit', CurriculumStatus::Draft, CurriculumStatus::PendingDeanReview, false],
            'Dean approves a submission' => ['dean_approve', CurriculumStatus::PendingDeanReview, CurriculumStatus::PendingExecutiveReview, false],
            'Dean returns a submission' => ['dean_return', CurriculumStatus::PendingDeanReview, CurriculumStatus::Draft, true],
            'Executive approves a Dean-approved submission' => ['executive_approve', CurriculumStatus::PendingExecutiveReview, CurriculumStatus::Active, false],
            'Executive returns a Dean-approved submission' => ['executive_return', CurriculumStatus::PendingExecutiveReview, CurriculumStatus::Draft, true],
        ];
    }

    public function test_actions_lists_exactly_the_five_known_actions(): void
    {
        self::assertSame(
            ['submit', 'dean_approve', 'dean_return', 'executive_approve', 'executive_return'],
            CurriculumTransitionRules::actions(),
        );
    }

    public function test_unknown_action_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CurriculumTransitionRules::requiredStatus('unknown');
    }
}
