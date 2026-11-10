<?php

namespace Tests\Unit\Models;

use App\Domain\Enrollment\QueueTicketPriority;
use App\Domain\Enrollment\QueueTicketStatus;
use App\Models\QueueTicket;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class QueueTicketTest extends TestCase
{
    public function test_status_and_dates_use_their_canonical_casts(): void
    {
        $ticket = new QueueTicket;
        $ticket->forceFill([
            'enrollment_id' => 1,
            'ticket_number' => 'Q-2026-0001',
            'queue_date' => '2026-08-05',
            'status' => 'served',
            'served_at' => '2026-08-05 09:00:00',
        ]);

        self::assertSame(QueueTicketStatus::Served, $ticket->status);
        self::assertInstanceOf(CarbonImmutable::class, $ticket->queue_date);
        self::assertInstanceOf(CarbonImmutable::class, $ticket->served_at);
    }

    public function test_priority_uses_its_canonical_cast(): void
    {
        $ticket = new QueueTicket;
        $ticket->forceFill([
            'enrollment_id' => 1,
            'ticket_number' => 'Q001',
            'queue_date' => '2026-08-05',
            'status' => 'waiting',
            'priority' => 'priority',
        ]);

        self::assertSame(QueueTicketPriority::Priority, $ticket->priority);
    }

    public function test_position_is_null_once_a_ticket_leaves_waiting(): void
    {
        $ticket = new QueueTicket;
        $ticket->forceFill([
            'enrollment_id' => 1,
            'ticket_number' => 'Q001',
            'queue_date' => '2026-08-05',
            'status' => 'served',
            'priority' => 'regular',
        ]);

        self::assertNull($ticket->position());
    }
}
