<?php

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\StudentType;
use PHPUnit\Framework\TestCase;

final class StudentTypeTest extends TestCase
{
    public function test_only_a_freshman_and_a_transferee_pay_first(): void
    {
        self::assertTrue(StudentType::Freshman->isPayFirst());
        self::assertTrue(StudentType::Transferee->isPayFirst());
        self::assertFalse(StudentType::Returnee->isPayFirst());
        self::assertFalse(StudentType::ExistingStudent->isPayFirst());
    }
}
