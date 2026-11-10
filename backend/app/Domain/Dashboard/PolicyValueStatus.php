<?php

namespace App\Domain\Dashboard;

/**
 * `Configured` — GRC has formally confirmed this value (none exist yet).
 * `Provisional` — a mechanism exists and a default is set, but the value is
 *   explicit user/developer direction, not a signed-off GRC decision (e.g.
 *   the grading comparison rule, see config/enrollment.php).
 * `Unset` — a mechanism exists (an env-overridable config key) but the
 *   value is deliberately null — "no cap enforced" until GRC sets one.
 * `NoMechanism` — no config key exists at all; PRD §17 names the decision
 *   but nothing in this codebase can act on it yet.
 */
enum PolicyValueStatus: string
{
    case Configured = 'configured';
    case Provisional = 'provisional';
    case Unset = 'unset';
    case NoMechanism = 'no_mechanism';

    public function label(): string
    {
        return match ($this) {
            self::Configured => 'Confirmed by GRC',
            self::Provisional => 'Provisional default',
            self::Unset => 'No value set',
            self::NoMechanism => 'No configuration mechanism yet',
        };
    }
}
