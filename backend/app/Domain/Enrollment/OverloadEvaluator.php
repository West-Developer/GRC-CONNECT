<?php

namespace App\Domain\Enrollment;

/**
 * FR-ENR-004: maximum regular units and the overload approval workflow.
 * Pure — no DB, no `config()` call in here; the caller reads
 * `config('enrollment.max_regular_units')` /
 * `config('enrollment.overload_max_units')` and passes primitives in, the
 * same boundary `App\Domain\Academic\PrerequisiteEvaluator` draws against
 * its own caller.
 *
 * Both config values default to `null` — "no cap enforced", the
 * mechanism-implemented/value-flagged pattern `config/enrollment.php`
 * already documents. With both null, `evaluate()` always returns
 * `WithinRegular`: this Action changes nothing about existing behavior
 * until GRC actually sets a value.
 */
final class OverloadEvaluator
{
    public static function evaluate(
        float $totalUnits,
        ?float $maxRegularUnits,
        ?float $overloadMaxUnits,
    ): OverloadVerdict {
        if ($maxRegularUnits === null || $totalUnits <= $maxRegularUnits) {
            return OverloadVerdict::WithinRegular;
        }

        if ($overloadMaxUnits !== null && $totalUnits <= $overloadMaxUnits) {
            return OverloadVerdict::RequiresApproval;
        }

        return OverloadVerdict::Rejected;
    }
}
