<?php

namespace App\Domain\Curriculum;

use InvalidArgumentException;

final class CurriculumTransitionRules
{
    /** @var array<string, CurriculumStatus> */
    private const REQUIRED_STATUS = [
        'submit' => CurriculumStatus::Draft,
        'dean_approve' => CurriculumStatus::PendingDeanReview,
        'dean_return' => CurriculumStatus::PendingDeanReview,
        'executive_approve' => CurriculumStatus::PendingExecutiveReview,
        'executive_return' => CurriculumStatus::PendingExecutiveReview,
    ];

    /** @var array<string, CurriculumStatus> */
    private const TARGET_STATUS = [
        'submit' => CurriculumStatus::PendingDeanReview,
        'dean_approve' => CurriculumStatus::PendingExecutiveReview,
        'dean_return' => CurriculumStatus::Draft,
        'executive_approve' => CurriculumStatus::Active,
        'executive_return' => CurriculumStatus::Draft,
    ];

    /** @return list<string> */
    public static function actions(): array
    {
        return array_keys(self::REQUIRED_STATUS);
    }

    public static function requiredStatus(string $action): CurriculumStatus
    {
        return self::REQUIRED_STATUS[$action]
            ?? throw new InvalidArgumentException('Unknown curriculum transition.');
    }

    public static function targetStatus(string $action): CurriculumStatus
    {
        return self::TARGET_STATUS[$action]
            ?? throw new InvalidArgumentException('Unknown curriculum transition.');
    }

    public static function isReturn(string $action): bool
    {
        return in_array($action, ['dean_return', 'executive_return'], true);
    }
}
