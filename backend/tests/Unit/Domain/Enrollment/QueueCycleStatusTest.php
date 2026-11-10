<?php

namespace Tests\Unit\Domain\Enrollment;

use App\Domain\Enrollment\QueueCycleStatus;
use PHPUnit\Framework\TestCase;

final class QueueCycleStatusTest extends TestCase
{
    public function test_it_has_the_three_provisional_values(): void
    {
        self::assertSame(['open', 'cut_off', 'closed'], array_map(
            fn (QueueCycleStatus $status): string => $status->value,
            QueueCycleStatus::cases(),
        ));
    }

    public function test_every_case_has_a_stable_label(): void
    {
        self::assertSame('Open', QueueCycleStatus::Open->label());
        self::assertSame('Cut off for today', QueueCycleStatus::CutOff->label());
        self::assertSame('Closed', QueueCycleStatus::Closed->label());
    }
}
