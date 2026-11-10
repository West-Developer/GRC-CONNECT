<?php

namespace Tests\Unit\Domain\Organization;

use App\Domain\Organization\AcademicTermStatus;
use PHPUnit\Framework\TestCase;

final class AcademicTermStatusTest extends TestCase
{
    public function test_status_values_are_the_manual_enrollment_lifecycle(): void
    {
        self::assertSame(
            [
                'draft',
                'for_dean_approval',
                'semester_ongoing',
                'semester_closed',
                'archived',
            ],
            array_column(AcademicTermStatus::cases(), 'value'),
        );
    }

    /**
     * @return array<string, array{AcademicTermStatus, string}>
     */
    public static function labelProvider(): array
    {
        return [
            'draft' => [AcademicTermStatus::Draft, 'Draft'],
            'for dean approval' => [AcademicTermStatus::ForDeanApproval, 'For Dean Approval'],
            'semester ongoing' => [AcademicTermStatus::SemesterOngoing, 'Semester Ongoing'],
            'semester closed' => [AcademicTermStatus::SemesterClosed, 'Semester Closed'],
            'archived' => [AcademicTermStatus::Archived, 'Archived'],
        ];
    }

    /**
     * @dataProvider labelProvider
     */
    public function test_labels_are_stable_and_human_readable(AcademicTermStatus $status, string $expectedLabel): void
    {
        self::assertSame($expectedLabel, $status->label());
    }

    public function test_preparation_and_approval_stages_are_not_visible_to_learners(): void
    {
        foreach ([
            AcademicTermStatus::Draft,
            AcademicTermStatus::ForDeanApproval,
        ] as $status) {
            self::assertFalse($status->isVisibleToLearners(), "{$status->value} should not be learner-visible.");
        }
    }

    public function test_ongoing_and_historical_stages_are_visible_to_learners(): void
    {
        foreach ([
            AcademicTermStatus::SemesterOngoing,
            AcademicTermStatus::SemesterClosed,
            AcademicTermStatus::Archived,
        ] as $status) {
            self::assertTrue($status->isVisibleToLearners(), "{$status->value} should be learner-visible.");
        }
    }
}
