<?php

namespace App\Domain\Enrollment;

use Carbon\CarbonImmutable;

/**
 * The physical front-desk's "today" — Asia/Manila by default — used only
 * for `queue_tickets.queue_date` and the `QueueCycle` drain/reset rule.
 * Deliberately NOT `config('app.timezone')` (UTC, unchanged everywhere
 * else): every stored timestamp column (`created_at`, `requeued_at`,
 * `served_at`, ...) stays UTC, and `QueueTicket::position()`'s COALESCE
 * ordering depends on comparing them as UTC. Converting `app.timezone`
 * instead would silently shift every one of those comparisons by the UTC
 * offset. See docs/superpowers/specs/
 * 2026-08-23-queue-kiosk-claim-carryover-cutoff-design.md.
 */
final class QueueServiceDate
{
    public static function today(): string
    {
        return CarbonImmutable::now(self::timezone())->toDateString();
    }

    public static function timezone(): string
    {
        return (string) config('enrollment.queue.timezone', 'Asia/Manila');
    }
}
