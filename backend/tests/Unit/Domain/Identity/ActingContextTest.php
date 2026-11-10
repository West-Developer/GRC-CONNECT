<?php

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\ActingContext;
use App\Domain\Identity\UserRole;
use App\Domain\Organization\CollegeCode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ActingContextTest extends TestCase
{
    public function test_dean_requires_a_college(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ActingContext::fromRequest(UserRole::Dean, null);
    }

    public function test_registrar_head_forbids_a_college(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ActingContext::fromRequest(UserRole::RegistrarHead, CollegeCode::Ccs);
    }

    public function test_only_switchable_roles_are_accepted(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ActingContext::fromRequest(UserRole::Faculty, null);
    }

    public function test_label(): void
    {
        $this->assertSame('Dean (CCS)', ActingContext::fromRequest(UserRole::Dean, CollegeCode::Ccs)->label());
        $this->assertSame('Registrar Head', ActingContext::fromRequest(UserRole::RegistrarHead, null)->label());
    }
}
