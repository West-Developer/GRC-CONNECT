<?php

namespace Tests\Unit\Domain\Enrollment;

use App\Domain\Enrollment\EnrollmentStatus;
use PHPUnit\Framework\TestCase;

final class EnrollmentStatusTest extends TestCase
{
    public function test_status_values_are_the_prd_lifecycle_plus_terminal_states(): void
    {
        self::assertSame(
            [
                'draft', 'pending_program_head_approval', 'pending_student_review', 'awaiting_first_payment', 'pending_registrar_approval', 'pending_payment', 'enrolled',
                'rejected', 'cancelled', 'withdrawn',
            ],
            array_column(EnrollmentStatus::cases(), 'value'),
        );
    }

    public function test_labels_are_stable_and_human_readable(): void
    {
        self::assertSame('Draft', EnrollmentStatus::Draft->label());
        self::assertSame('Pending Program Head Approval', EnrollmentStatus::PendingProgramHeadApproval->label());
        self::assertSame('Awaiting Student Review', EnrollmentStatus::PendingStudentReview->label());
        self::assertSame('Awaiting First Payment', EnrollmentStatus::AwaitingFirstPayment->label());
        self::assertSame('Pending Registrar Approval', EnrollmentStatus::PendingRegistrarApproval->label());
        self::assertSame('Pending Payment', EnrollmentStatus::PendingPayment->label());
        self::assertSame('Enrolled', EnrollmentStatus::Enrolled->label());
        self::assertSame('Rejected', EnrollmentStatus::Rejected->label());
        self::assertSame('Cancelled', EnrollmentStatus::Cancelled->label());
        self::assertSame('Withdrawn', EnrollmentStatus::Withdrawn->label());
    }

    public function test_only_rejected_cancelled_and_withdrawn_are_terminal(): void
    {
        self::assertFalse(EnrollmentStatus::Draft->isTerminal());
        self::assertFalse(EnrollmentStatus::PendingProgramHeadApproval->isTerminal());
        self::assertFalse(EnrollmentStatus::PendingStudentReview->isTerminal());
        self::assertFalse(EnrollmentStatus::AwaitingFirstPayment->isTerminal());
        self::assertFalse(EnrollmentStatus::PendingRegistrarApproval->isTerminal());
        self::assertFalse(EnrollmentStatus::PendingPayment->isTerminal());
        self::assertFalse(EnrollmentStatus::Enrolled->isTerminal());
        self::assertTrue(EnrollmentStatus::Rejected->isTerminal());
        self::assertTrue(EnrollmentStatus::Cancelled->isTerminal());
        self::assertTrue(EnrollmentStatus::Withdrawn->isTerminal());
    }

    public function test_is_active_is_the_exact_inverse_of_is_terminal(): void
    {
        foreach (EnrollmentStatus::cases() as $status) {
            self::assertSame(! $status->isTerminal(), $status->isActive());
        }
    }

    public function test_terminal_values_matches_the_generated_column_definition(): void
    {
        self::assertSame(
            ['rejected', 'cancelled', 'withdrawn'],
            EnrollmentStatus::terminalValues(),
        );
    }
}
