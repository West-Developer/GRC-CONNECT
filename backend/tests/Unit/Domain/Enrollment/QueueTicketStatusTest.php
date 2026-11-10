<?php

namespace Tests\Unit\Domain\Enrollment;

use App\Domain\Enrollment\QueueTicketStatus;
use PHPUnit\Framework\TestCase;

final class QueueTicketStatusTest extends TestCase
{
    public function test_status_values_are_the_four_provisional_cases(): void
    {
        self::assertSame(
            ['waiting', 'serving', 'served', 'cancelled'],
            array_column(QueueTicketStatus::cases(), 'value'),
        );
    }

    public function test_labels_are_stable_and_human_readable(): void
    {
        self::assertSame('Waiting', QueueTicketStatus::Waiting->label());
        self::assertSame('Serving', QueueTicketStatus::Serving->label());
        self::assertSame('Served', QueueTicketStatus::Served->label());
        self::assertSame('Cancelled', QueueTicketStatus::Cancelled->label());
    }
}
