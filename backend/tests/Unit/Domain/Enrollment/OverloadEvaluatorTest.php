<?php

namespace Tests\Unit\Domain\Enrollment;

use App\Domain\Enrollment\OverloadEvaluator;
use App\Domain\Enrollment\OverloadVerdict;
use PHPUnit\Framework\TestCase;

final class OverloadEvaluatorTest extends TestCase
{
    public function test_both_caps_unset_always_permits_any_load(): void
    {
        self::assertSame(
            OverloadVerdict::WithinRegular,
            OverloadEvaluator::evaluate(30.0, null, null),
        );
    }

    public function test_at_or_under_the_regular_cap_is_within_regular(): void
    {
        self::assertSame(OverloadVerdict::WithinRegular, OverloadEvaluator::evaluate(18.0, 18.0, 24.0));
        self::assertSame(OverloadVerdict::WithinRegular, OverloadEvaluator::evaluate(15.0, 18.0, 24.0));
    }

    public function test_over_the_regular_cap_with_no_overload_allowance_is_rejected(): void
    {
        self::assertSame(OverloadVerdict::Rejected, OverloadEvaluator::evaluate(19.0, 18.0, null));
    }

    public function test_over_the_regular_cap_but_within_the_overload_allowance_requires_approval(): void
    {
        self::assertSame(OverloadVerdict::RequiresApproval, OverloadEvaluator::evaluate(20.0, 18.0, 24.0));
        // At the overload ceiling itself is still permitted, not rejected.
        self::assertSame(OverloadVerdict::RequiresApproval, OverloadEvaluator::evaluate(24.0, 18.0, 24.0));
    }

    public function test_over_the_overload_allowance_itself_is_rejected(): void
    {
        self::assertSame(OverloadVerdict::Rejected, OverloadEvaluator::evaluate(25.0, 18.0, 24.0));
    }
}
