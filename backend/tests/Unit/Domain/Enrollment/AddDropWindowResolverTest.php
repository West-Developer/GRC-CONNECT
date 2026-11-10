<?php

namespace Tests\Unit\Domain\Enrollment;

use App\Domain\Enrollment\AddDropAvailabilityReason;
use App\Domain\Enrollment\AddDropWindowResolver;
use App\Domain\Organization\AcademicTermStatus;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AddDropWindowResolverTest extends TestCase
{
    private const NOW = '2026-08-10 12:00:00';

    #[DataProvider('nonOngoingStatuses')]
    public function test_a_term_that_is_not_semester_ongoing_is_never_open(AcademicTermStatus $status): void
    {
        $now = CarbonImmutable::parse(self::NOW);

        $availability = AddDropWindowResolver::resolve($status, $now->subDay(), $now->addDay(), $now);

        self::assertFalse($availability->isOpen);
        self::assertSame(AddDropAvailabilityReason::TermNotOngoing, $availability->reason);
    }

    /** @return array<string, array{AcademicTermStatus}> */
    public static function nonOngoingStatuses(): array
    {
        return [
            'draft' => [AcademicTermStatus::Draft],
            'for dean approval' => [AcademicTermStatus::ForDeanApproval],
            'semester closed' => [AcademicTermStatus::SemesterClosed],
            'archived' => [AcademicTermStatus::Archived],
        ];
    }

    public function test_the_window_is_blocked_while_enrollment_is_still_open(): void
    {
        $now = CarbonImmutable::parse(self::NOW);

        $availability = AddDropWindowResolver::resolve(
            AcademicTermStatus::SemesterOngoing,
            $now->addDay(),
            $now->addDays(30),
            $now,
        );

        self::assertFalse($availability->isOpen);
        self::assertSame(AddDropAvailabilityReason::EnrollmentStillOpen, $availability->reason);
    }

    public function test_a_missing_enrollment_close_date_blocks_the_window(): void
    {
        $now = CarbonImmutable::parse(self::NOW);

        $availability = AddDropWindowResolver::resolve(
            AcademicTermStatus::SemesterOngoing,
            null,
            $now->addDays(30),
            $now,
        );

        self::assertFalse($availability->isOpen);
        self::assertSame(AddDropAvailabilityReason::EnrollmentStillOpen, $availability->reason);
    }

    public function test_a_missing_deadline_blocks_the_window_even_after_enrollment_closes(): void
    {
        $now = CarbonImmutable::parse(self::NOW);

        $availability = AddDropWindowResolver::resolve(
            AcademicTermStatus::SemesterOngoing,
            $now->subDay(),
            null,
            $now,
        );

        self::assertFalse($availability->isOpen);
        self::assertSame(AddDropAvailabilityReason::DeadlineNotConfigured, $availability->reason);
    }

    public function test_the_window_is_blocked_after_the_deadline_has_passed(): void
    {
        $now = CarbonImmutable::parse(self::NOW);

        $availability = AddDropWindowResolver::resolve(
            AcademicTermStatus::SemesterOngoing,
            $now->subDays(10),
            $now->subDay(),
            $now,
        );

        self::assertFalse($availability->isOpen);
        self::assertSame(AddDropAvailabilityReason::DeadlinePassed, $availability->reason);
    }

    public function test_the_window_is_open_between_enrollment_close_and_the_deadline(): void
    {
        $now = CarbonImmutable::parse(self::NOW);

        $availability = AddDropWindowResolver::resolve(
            AcademicTermStatus::SemesterOngoing,
            $now->subDay(),
            $now->addDay(),
            $now,
        );

        self::assertTrue($availability->isOpen);
        self::assertSame(AddDropAvailabilityReason::Open, $availability->reason);
    }

    public function test_the_close_and_deadline_instants_are_inclusive(): void
    {
        $now = CarbonImmutable::parse(self::NOW);

        $atClose = AddDropWindowResolver::resolve(AcademicTermStatus::SemesterOngoing, $now, $now->addDay(), $now);
        $atDeadline = AddDropWindowResolver::resolve(AcademicTermStatus::SemesterOngoing, $now->subDay(), $now, $now);

        self::assertTrue($atClose->isOpen);
        self::assertTrue($atDeadline->isOpen);
    }

    public function test_already_enrolled_student_can_access_add_drop_while_enrollment_still_open(): void
    {
        $now = CarbonImmutable::parse(self::NOW);

        // Enrollment window is still open (closes in the future) — normally blocked
        $availability = AddDropWindowResolver::resolve(
            AcademicTermStatus::SemesterOngoing,
            $now->addDay(),         // enrollment still open
            $now->addDays(30),      // add/drop deadline in future
            $now,
            true,                   // student is already enrolled
        );

        self::assertTrue($availability->isOpen);
        self::assertSame(AddDropAvailabilityReason::Open, $availability->reason);
    }

    public function test_already_enrolled_student_with_null_enrollment_close_date_is_still_open(): void
    {
        $now = CarbonImmutable::parse(self::NOW);

        // enrollment_closes_at is null — normally would block with EnrollmentStillOpen
        $availability = AddDropWindowResolver::resolve(
            AcademicTermStatus::SemesterOngoing,
            null,
            $now->addDays(30),
            $now,
            true,
        );

        self::assertTrue($availability->isOpen);
        self::assertSame(AddDropAvailabilityReason::Open, $availability->reason);
    }

    public function test_already_enrolled_student_is_blocked_after_add_drop_deadline_passes(): void
    {
        $now = CarbonImmutable::parse(self::NOW);

        $availability = AddDropWindowResolver::resolve(
            AcademicTermStatus::SemesterOngoing,
            $now->addDay(),     // enrollment still open (irrelevant for enrolled student)
            $now->subDay(),     // add/drop deadline already passed
            $now,
            true,
        );

        self::assertFalse($availability->isOpen);
        self::assertSame(AddDropAvailabilityReason::DeadlinePassed, $availability->reason);
    }

    public function test_already_enrolled_student_is_blocked_when_term_is_not_ongoing(): void
    {
        $now = CarbonImmutable::parse(self::NOW);

        $availability = AddDropWindowResolver::resolve(
            AcademicTermStatus::SemesterClosed,
            $now->subDay(),
            $now->addDay(),
            $now,
            true,
        );

        self::assertFalse($availability->isOpen);
        self::assertSame(AddDropAvailabilityReason::TermNotOngoing, $availability->reason);
    }
}
