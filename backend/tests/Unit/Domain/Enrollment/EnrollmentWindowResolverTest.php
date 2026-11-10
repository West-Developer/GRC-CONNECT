<?php

namespace Tests\Unit\Domain\Enrollment;

use App\Domain\Enrollment\EnrollmentAvailabilityReason;
use App\Domain\Enrollment\EnrollmentWindowResolver;
use App\Domain\Organization\AcademicTermStatus;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnrollmentWindowResolverTest extends TestCase
{
    private const NOW = '2026-08-10 12:00:00';

    #[DataProvider('nonOngoingStatuses')]
    public function test_a_term_that_is_not_semester_ongoing_is_never_open(
        AcademicTermStatus $status,
        EnrollmentAvailabilityReason $expectedReason,
    ): void {
        $now = CarbonImmutable::parse(self::NOW);

        // Dates that would otherwise be wide open — status alone must still
        // block submission, per ADR 0018's "no date alone silently changes
        // lifecycle status."
        $availability = EnrollmentWindowResolver::resolve(
            $status,
            $now->subYear(),
            $now->addYear(),
            $now->subYear(),
            $now->addYear(),
            $now,
        );

        self::assertFalse($availability->isOpen);
        self::assertSame($expectedReason, $availability->reason);
    }

    /** @return array<string, array{AcademicTermStatus, EnrollmentAvailabilityReason}> */
    public static function nonOngoingStatuses(): array
    {
        return [
            'draft' => [AcademicTermStatus::Draft, EnrollmentAvailabilityReason::TermNotOpen],
            'for dean approval' => [AcademicTermStatus::ForDeanApproval, EnrollmentAvailabilityReason::TermNotOpen],
            'semester closed' => [AcademicTermStatus::SemesterClosed, EnrollmentAvailabilityReason::TermClosed],
            'archived' => [AcademicTermStatus::Archived, EnrollmentAvailabilityReason::TermClosed],
        ];
    }

    public function test_before_the_year_level_window_is_blocked(): void
    {
        $now = CarbonImmutable::parse(self::NOW);

        $availability = EnrollmentWindowResolver::resolve(
            AcademicTermStatus::SemesterOngoing,
            $now->subDay(),
            $now->addDays(30),
            $now->addDay(),
            $now->addDays(10),
            $now,
        );

        self::assertFalse($availability->isOpen);
        self::assertSame(EnrollmentAvailabilityReason::BeforeWindow, $availability->reason);
        self::assertTrue($availability->opensAt?->equalTo($now->addDay()));
    }

    public function test_after_the_year_level_window_is_blocked(): void
    {
        $now = CarbonImmutable::parse(self::NOW);

        $availability = EnrollmentWindowResolver::resolve(
            AcademicTermStatus::SemesterOngoing,
            $now->subDays(30),
            $now->addDays(30),
            $now->subDays(10),
            $now->subDay(),
            $now,
        );

        self::assertFalse($availability->isOpen);
        self::assertSame(EnrollmentAvailabilityReason::AfterWindow, $availability->reason);
    }

    public function test_inside_the_year_level_window_is_open(): void
    {
        $now = CarbonImmutable::parse(self::NOW);

        $availability = EnrollmentWindowResolver::resolve(
            AcademicTermStatus::SemesterOngoing,
            $now->subDays(30),
            $now->addDays(30),
            $now->subDay(),
            $now->addDay(),
            $now,
        );

        self::assertTrue($availability->isOpen);
        self::assertSame(EnrollmentAvailabilityReason::Open, $availability->reason);
    }

    public function test_the_open_and_close_instants_are_inclusive(): void
    {
        $now = CarbonImmutable::parse(self::NOW);

        $atOpen = EnrollmentWindowResolver::resolve(
            AcademicTermStatus::SemesterOngoing,
            null,
            null,
            $now,
            $now->addDay(),
            $now,
        );
        $atClose = EnrollmentWindowResolver::resolve(
            AcademicTermStatus::SemesterOngoing,
            null,
            null,
            $now->subDay(),
            $now,
            $now,
        );

        self::assertTrue($atOpen->isOpen);
        self::assertTrue($atClose->isOpen);
    }

    public function test_a_year_level_with_no_override_falls_back_to_the_term_wide_window(): void
    {
        $now = CarbonImmutable::parse(self::NOW);

        $availability = EnrollmentWindowResolver::resolve(
            AcademicTermStatus::SemesterOngoing,
            $now->subDay(),
            $now->addDay(),
            null,
            null,
            $now,
        );

        self::assertTrue($availability->isOpen);
        self::assertSame(EnrollmentAvailabilityReason::Open, $availability->reason);
        self::assertTrue($availability->opensAt?->equalTo($now->subDay()));
        self::assertTrue($availability->closesAt?->equalTo($now->addDay()));
    }

    public function test_a_term_open_with_no_dates_at_all_is_open(): void
    {
        $now = CarbonImmutable::parse(self::NOW);

        $availability = EnrollmentWindowResolver::resolve(
            AcademicTermStatus::SemesterOngoing,
            null,
            null,
            null,
            null,
            $now,
        );

        self::assertTrue($availability->isOpen);
        self::assertSame(EnrollmentAvailabilityReason::Open, $availability->reason);
        self::assertNull($availability->opensAt);
        self::assertNull($availability->closesAt);
    }
}
