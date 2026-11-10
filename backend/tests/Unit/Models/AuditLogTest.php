<?php

namespace Tests\Unit\Models;

use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tests\TestCase;

final class AuditLogTest extends TestCase
{
    public function test_audit_payloads_and_timestamps_use_their_canonical_casts(): void
    {
        $auditLog = new AuditLog;
        $auditLog->forceFill([
            'before_values' => ['status' => 'draft'],
            'after_values' => ['status' => 'published'],
            'created_at' => '2026-07-29 08:00:00',
            'updated_at' => '2026-07-29 08:30:00',
        ]);

        self::assertSame(['status' => 'draft'], $auditLog->before_values);
        self::assertSame(['status' => 'published'], $auditLog->after_values);
        self::assertInstanceOf(CarbonImmutable::class, $auditLog->created_at);
        self::assertInstanceOf(CarbonImmutable::class, $auditLog->updated_at);
    }

    public function test_actor_relationship_targets_the_auditing_user(): void
    {
        $relation = (new AuditLog)->actor();

        self::assertInstanceOf(BelongsTo::class, $relation);
        self::assertInstanceOf(User::class, $relation->getRelated());
        self::assertSame('actor_user_id', $relation->getForeignKeyName());
    }

    public function test_user_exposes_its_audit_history(): void
    {
        $relation = (new User)->auditLogs();

        self::assertInstanceOf(HasMany::class, $relation);
        self::assertInstanceOf(AuditLog::class, $relation->getRelated());
        self::assertSame('actor_user_id', $relation->getForeignKeyName());
    }
}
