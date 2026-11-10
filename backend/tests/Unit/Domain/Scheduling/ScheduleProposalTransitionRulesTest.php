<?php

namespace Tests\Unit\Domain\Scheduling;

use App\Domain\Scheduling\ScheduleProposalStatus;
use App\Domain\Scheduling\ScheduleProposalTransitionRules;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScheduleProposalTransitionRulesTest extends TestCase
{
    #[DataProvider('checkpointActions')]
    public function test_checkpoint_actions_use_the_status_the_reviewer_actually_receives(
        string $action,
        ScheduleProposalStatus $required,
        ScheduleProposalStatus $target,
        bool $isReturn,
    ): void {
        self::assertSame($required, ScheduleProposalTransitionRules::requiredStatus($action));
        self::assertSame($target, ScheduleProposalTransitionRules::targetStatus($action));
        self::assertSame($isReturn, ScheduleProposalTransitionRules::isReturn($action));
    }

    /** @return array<string, array{string, ScheduleProposalStatus, ScheduleProposalStatus, bool}> */
    public static function checkpointActions(): array
    {
        return [
            'Dean approves pending submission' => ['dean_approve', ScheduleProposalStatus::Draft, ScheduleProposalStatus::DeanApproved, false],
            'Dean returns pending submission' => ['dean_return', ScheduleProposalStatus::Draft, ScheduleProposalStatus::Draft, true],
            'Executive returns Dean-approved submission' => ['executive_return', ScheduleProposalStatus::DeanApproved, ScheduleProposalStatus::Draft, true],
            'Executive publishes Dean-approved submission directly' => ['publish', ScheduleProposalStatus::DeanApproved, ScheduleProposalStatus::Published, false],
            'Registrar closes published submission' => ['close', ScheduleProposalStatus::Published, ScheduleProposalStatus::Closed, false],
        ];
    }

    public function test_unknown_action_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ScheduleProposalTransitionRules::requiredStatus('unknown');
    }

    /**
     * The two-step approve-then-publish flow was retired in favor of a
     * direct `publish` — `executive_approve` must no longer be a legal
     * action at all.
     */
    public function test_executive_approve_is_no_longer_a_known_action(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ScheduleProposalTransitionRules::requiredStatus('executive_approve');
    }
}
