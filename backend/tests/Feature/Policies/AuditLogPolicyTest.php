<?php

namespace Tests\Feature\Policies;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use App\Policies\AuditLogPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuditLogPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_registrar_head_may_view_audit_logs(): void
    {
        $policy = new AuditLogPolicy;
        $auditLog = new AuditLog;

        foreach (UserRole::cases() as $role) {
            $user = User::create([
                'name' => $role->label(),
                'email' => 'audit-policy-'.$role->value.'@grc.test',
                'password' => 'irrelevant-password',
                'role' => $role,
                'status' => UserStatus::Active,
            ]);
            $expected = in_array($role, [UserRole::RegistrarHead, UserRole::SuperAdmin], true);

            self::assertSame($expected, $policy->viewAny($user), $role->value.' viewAny result');
            self::assertSame($expected, $policy->view($user, $auditLog), $role->value.' view result');
        }
    }
}
