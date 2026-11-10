<?php

namespace Tests\Unit\Models;

use App\Domain\Identity\StudentType;
use App\Models\StudentProfile;
use Tests\TestCase;

final class StudentProfilePayFirstTest extends TestCase
{
    private function profile(StudentType $type, ?string $paidAt): StudentProfile
    {
        return (new StudentProfile)->forceFill([
            'student_type' => $type->value,
            'freshman_payment_confirmed_at' => $paidAt,
        ]);
    }

    public function test_a_pay_first_student_awaits_the_first_payment_until_the_cashier_confirms_it(): void
    {
        self::assertTrue($this->profile(StudentType::Freshman, null)->isAwaitingFirstPayment());
        self::assertTrue($this->profile(StudentType::Transferee, null)->isAwaitingFirstPayment());
        self::assertFalse($this->profile(StudentType::Freshman, '2026-10-05 10:00:00')->isAwaitingFirstPayment());
        self::assertFalse($this->profile(StudentType::Transferee, '2026-10-05 10:00:00')->isAwaitingFirstPayment());
    }

    public function test_a_student_who_does_not_pay_first_never_awaits_the_first_payment(): void
    {
        self::assertFalse($this->profile(StudentType::Returnee, null)->isAwaitingFirstPayment());
        self::assertFalse($this->profile(StudentType::ExistingStudent, null)->isAwaitingFirstPayment());
    }

    public function test_only_a_freshman_is_freshman_awaiting_payment_a_transferee_can_still_sign_in(): void
    {
        self::assertTrue($this->profile(StudentType::Freshman, null)->isFreshmanAwaitingPayment());
        self::assertFalse($this->profile(StudentType::Transferee, null)->isFreshmanAwaitingPayment());
    }
}
