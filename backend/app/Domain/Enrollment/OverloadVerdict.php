<?php

namespace App\Domain\Enrollment;

/**
 * The result of comparing a submission's total units against
 * `config('enrollment.max_regular_units')` and
 * `config('enrollment.overload_max_units')` — see `OverloadEvaluator`.
 */
enum OverloadVerdict
{
    /** At or under the regular cap (or no cap configured at all). */
    case WithinRegular;

    /** Over the regular cap but within the overload allowance — permitted,
     * but the enrollment carries a flag Registrar Staff must acknowledge
     * before approving. */
    case RequiresApproval;

    /** Over the regular cap with no overload allowance configured, or over
     * the overload allowance itself — submission is rejected. */
    case Rejected;
}
