<?php

namespace Tests\Unit\Models;

use App\Domain\Scheduling\ScheduleProposalStatus;
use App\Models\ScheduleProposal;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class ScheduleProposalTest extends TestCase
{
    public function test_status_attribute_uses_the_canonical_enum_cast(): void
    {
        $proposal = new ScheduleProposal;
        $proposal->forceFill([
            'academic_term_id' => 1,
            'submitted_by' => 1,
            'status' => 'draft',
        ]);

        self::assertSame(ScheduleProposalStatus::Draft, $proposal->status);
    }

    public function test_decided_at_is_cast_to_carbon_immutable(): void
    {
        $proposal = new ScheduleProposal;
        $proposal->forceFill([
            'academic_term_id' => 1,
            'submitted_by' => 1,
            'status' => 'dean_approved',
            'decided_at' => '2026-08-01 10:00:00',
        ]);

        self::assertInstanceOf(CarbonImmutable::class, $proposal->decided_at);
    }
}
