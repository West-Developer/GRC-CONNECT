<?php

namespace Tests\Feature\Database;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AuditAndNotificationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_and_notification_tables_have_the_approved_columns(): void
    {
        $this->assertTrue(Schema::hasTable('audit_logs'));
        $this->assertSame([
            'id', 'actor_user_id', 'acting_role', 'acting_college', 'action', 'auditable_type', 'auditable_id',
            'before_values', 'after_values', 'reason', 'request_id', 'ip_address',
            'created_at', 'updated_at',
        ], Schema::getColumnListing('audit_logs'));

        $this->assertTrue(Schema::hasTable('notifications'));
        $this->assertSame([
            'id', 'user_id', 'type', 'message', 'read_at', 'created_at', 'updated_at',
        ], Schema::getColumnListing('notifications'));
    }

    public function test_a_user_referenced_by_an_audit_log_cannot_be_deleted(): void
    {
        $actor = $this->makeUser();
        $this->insertAuditLog($actor->id);

        $this->expectException(QueryException::class);

        $actor->delete();
    }

    public function test_deleting_a_user_cascades_to_their_notifications(): void
    {
        $user = $this->makeUser();

        $notificationId = DB::table('notifications')->insertGetId([
            'user_id' => $user->id,
            'type' => 'schedule_published',
            'message' => 'Your schedule has been published.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user->delete();

        $this->assertDatabaseMissing('notifications', ['id' => $notificationId]);
    }

    public function test_a_request_id_longer_than_128_characters_is_rejected(): void
    {
        try {
            $this->insertAuditLog($this->makeUser()->id, [
                'request_id' => str_repeat('r', 129),
            ]);
        } catch (QueryException $exception) {
            $this->assertStringContainsString("Data too long for column 'request_id'", $exception->getMessage());

            return;
        }

        $this->fail('Expected MariaDB to reject a request_id longer than 128 characters.');
    }

    public function test_audit_logs_allow_nullable_context_fields(): void
    {
        $auditLogId = $this->insertAuditLog($this->makeUser()->id, [
            'auditable_id' => null,
            'before_values' => null,
            'after_values' => null,
            'reason' => null,
            'ip_address' => null,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'id' => $auditLogId,
            'auditable_id' => null,
            'before_values' => null,
            'after_values' => null,
            'reason' => null,
            'ip_address' => null,
        ]);
    }

    public function test_audit_logs_preserve_before_and_after_json_objects(): void
    {
        $beforeValues = ['status' => 'draft', 'capacity' => 30];
        $afterValues = ['status' => 'published', 'capacity' => 40];

        $auditLogId = $this->insertAuditLog($this->makeUser()->id, [
            'before_values' => json_encode($beforeValues, JSON_THROW_ON_ERROR),
            'after_values' => json_encode($afterValues, JSON_THROW_ON_ERROR),
        ]);

        $auditLog = DB::table('audit_logs')->find($auditLogId);

        $this->assertNotNull($auditLog);
        $this->assertSame($beforeValues, json_decode($auditLog->before_values, true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame($afterValues, json_decode($auditLog->after_values, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_audit_and_notification_history_indexes_exist(): void
    {
        $expectedAuditIndexes = [
            'audit_logs_action_history_index',
            'audit_logs_actor_history_index',
            'audit_logs_auditable_history_index',
            'audit_logs_created_at_index',
            'audit_logs_request_id_index',
        ];
        $expectedNotificationIndexes = [
            'notifications_user_created_index',
            'notifications_user_unread_created_index',
        ];

        $this->assertSame($expectedAuditIndexes, $this->indexNamesFor('audit_logs', $expectedAuditIndexes));
        $this->assertSame($expectedNotificationIndexes, $this->indexNamesFor('notifications', $expectedNotificationIndexes));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertAuditLog(int $actorUserId, array $overrides = []): int
    {
        return DB::table('audit_logs')->insertGetId(array_merge([
            'actor_user_id' => $actorUserId,
            'action' => 'schedule.published',
            'auditable_type' => 'schedule_proposal',
            'auditable_id' => 1,
            'before_values' => json_encode(['status' => 'draft'], JSON_THROW_ON_ERROR),
            'after_values' => json_encode(['status' => 'published'], JSON_THROW_ON_ERROR),
            'reason' => 'Approved by the Executive Director.',
            'request_id' => 'request-'.uniqid(),
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param  list<string>  $expectedIndexNames
     * @return list<string>
     */
    private function indexNamesFor(string $table, array $expectedIndexNames): array
    {
        $placeholders = implode(', ', array_fill(0, count($expectedIndexNames), '?'));

        /** @var list<object{INDEX_NAME: string}> $indexes */
        $indexes = DB::select(
            "select distinct INDEX_NAME from information_schema.statistics where table_schema = ? and table_name = ? and INDEX_NAME in ({$placeholders}) order by INDEX_NAME",
            array_merge([DB::connection()->getDatabaseName(), $table], $expectedIndexNames),
        );

        return array_map(static fn (object $index): string => $index->INDEX_NAME, $indexes);
    }

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Audit Test User',
            'email' => 'audit-user-'.uniqid().'@grc.test',
            'password' => 'irrelevant-password',
            'role' => UserRole::RegistrarHead,
            'status' => UserStatus::Active,
        ]);
    }
}
