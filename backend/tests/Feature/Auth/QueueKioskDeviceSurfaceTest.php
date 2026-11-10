<?php

namespace Tests\Feature\Auth;

use App\Domain\Identity\QueueKioskAccess;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class QueueKioskDeviceSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(UserRole $role, string $email): string
    {
        $user = User::create([
            'name' => "{$role->label()} test identity",
            'email' => $email,
            'password' => 'correct-horse-battery-staple',
            'role' => $role,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);

        $abilities = $role === UserRole::QueueKiosk
            ? [QueueKioskAccess::TOKEN_ABILITY]
            : ['*'];

        return $user->createToken('test-device-surface', $abilities)->plainTextToken;
    }

    public function test_a_queue_kiosk_bearer_can_only_use_its_identity_endpoints(): void
    {
        $token = $this->tokenFor(UserRole::QueueKiosk, 'queue-kiosk.surface@grc.test');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        foreach ([
            ['GET', '/api/v1/programs'],
            ['GET', '/api/v1/academic-terms'],
            ['GET', '/api/v1/queue-status'],
            ['POST', '/api/v1/queue-tickets'],
        ] as [$method, $uri]) {
            $this->withToken($token)->json($method, $uri)->assertForbidden();
        }

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
    }

    public function test_student_and_accounting_bearers_keep_their_existing_program_access(): void
    {
        $studentToken = $this->tokenFor(UserRole::Student, 'student.surface@grc.test');
        $accountingToken = $this->tokenFor(UserRole::AccountingStaff, 'accounting.surface@grc.test');

        $this->withToken($studentToken)->getJson('/api/v1/programs')->assertOk();
        $this->withToken($accountingToken)->getJson('/api/v1/programs')->assertOk();
    }
}
