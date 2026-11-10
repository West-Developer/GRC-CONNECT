<?php

namespace App\Domain\Scheduling;

use InvalidArgumentException;

final class ScheduleProposalTransitionRules
{
    /**
     * `executive_approve` is deliberately absent: the Executive Director's
     * lifecycle was simplified from a two-step approve-then-publish to one
     * direct `publish` (or `executive_return`) straight from `DeanApproved`
     * — a product-owner amendment to the PRD §4.1 lifecycle this class's
     * own docblock once called authoritative. `ScheduleProposalStatus::
     * ExecutiveApproved` stays defined for any pre-existing row already in
     * that state, but no new transition can ever reach or leave it.
     *
     * @var array<string, ScheduleProposalStatus>
     */
    private const REQUIRED_STATUS = [
        'dean_approve' => ScheduleProposalStatus::Draft,
        'dean_return' => ScheduleProposalStatus::Draft,
        'executive_return' => ScheduleProposalStatus::DeanApproved,
        'publish' => ScheduleProposalStatus::DeanApproved,
        'close' => ScheduleProposalStatus::Published,
    ];

    /** @var array<string, ScheduleProposalStatus> */
    private const TARGET_STATUS = [
        'dean_approve' => ScheduleProposalStatus::DeanApproved,
        'dean_return' => ScheduleProposalStatus::Draft,
        'executive_return' => ScheduleProposalStatus::Draft,
        'publish' => ScheduleProposalStatus::Published,
        'close' => ScheduleProposalStatus::Closed,
    ];

    /** @return list<string> */
    public static function actions(): array
    {
        return array_keys(self::REQUIRED_STATUS);
    }

    public static function requiredStatus(string $action): ScheduleProposalStatus
    {
        return self::REQUIRED_STATUS[$action]
            ?? throw new InvalidArgumentException('Unknown schedule proposal transition.');
    }

    public static function targetStatus(string $action): ScheduleProposalStatus
    {
        return self::TARGET_STATUS[$action]
            ?? throw new InvalidArgumentException('Unknown schedule proposal transition.');
    }

    public static function isReturn(string $action): bool
    {
        return in_array($action, ['dean_return', 'executive_return'], true);
    }
}
