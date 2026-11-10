<?php

namespace Tests\Feature\Models;

use App\Domain\Audit\AuditAction;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

final class AuditLogImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_persisted_audit_logs_cannot_be_updated_through_eloquent(): void
    {
        $auditLog = $this->makeAuditLog();

        try {
            $auditLog->update(['action' => AuditAction::CURRICULUM_UPDATED]);
            self::fail('Updating a persisted audit log must throw a LogicException.');
        } catch (LogicException $exception) {
            self::assertSame('Audit logs are immutable.', $exception->getMessage());
        }

        self::assertSame(AuditAction::CURRICULUM_CREATED, $auditLog->fresh()->action);
    }

    public function test_persisted_audit_logs_cannot_be_deleted_through_eloquent(): void
    {
        $auditLog = $this->makeAuditLog();

        try {
            $auditLog->delete();
            self::fail('Deleting a persisted audit log must throw a LogicException.');
        } catch (LogicException $exception) {
            self::assertSame('Audit logs are immutable.', $exception->getMessage());
        }

        self::assertDatabaseHas('audit_logs', ['id' => $auditLog->id]);
    }

    private function makeAuditLog(): AuditLog
    {
        $user = User::create([
            'name' => 'Audit Actor',
            'email' => 'audit-actor@grc.test',
            'password' => 'irrelevant-password',
            'role' => UserRole::RegistrarHead,
            'status' => UserStatus::Active,
        ]);

        return AuditLog::create([
            'actor_user_id' => $user->id,
            'action' => AuditAction::CURRICULUM_CREATED,
            'auditable_type' => 'curriculum',
            'auditable_id' => 1,
            'before_values' => null,
            'after_values' => ['status' => 'draft'],
            'reason' => null,
            'request_id' => 'test-request-id',
            'ip_address' => '127.0.0.1',
        ]);
    }
}
