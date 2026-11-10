<?php

namespace Tests\Unit\Domain\Enrollment;

use App\Domain\Enrollment\EnrollmentRouting;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Identity\StudentType;
use App\Models\StudentProfile;
use Tests\TestCase;

final class EnrollmentRoutingTest extends TestCase
{
    private function profile(StudentType $type, ?string $paidAt = null): StudentProfile
    {
        return (new StudentProfile)->forceFill([
            'student_type' => $type->value,
            'freshman_payment_confirmed_at' => $paidAt,
        ]);
    }

    public function test_a_transferee_who_has_not_paid_waits_at_the_payment_hold_after_review(): void
    {
        self::assertSame(
            EnrollmentStatus::AwaitingFirstPayment,
            EnrollmentRouting::statusAfterReview($this->profile(StudentType::Transferee)),
        );
    }

    public function test_a_transferee_who_already_paid_goes_straight_to_the_registrar(): void
    {
        self::assertSame(
            EnrollmentStatus::PendingRegistrarApproval,
            EnrollmentRouting::statusAfterReview($this->profile(StudentType::Transferee, '2026-10-05 10:00:00')),
        );
    }

    public function test_every_other_student_goes_straight_to_the_registrar(): void
    {
        foreach ([StudentType::Freshman, StudentType::Returnee, StudentType::ExistingStudent] as $type) {
            self::assertSame(
                EnrollmentStatus::PendingRegistrarApproval,
                EnrollmentRouting::statusAfterReview($this->profile($type)),
                $type->value,
            );
        }
    }
}
