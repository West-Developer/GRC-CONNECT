<?php

namespace Tests\Feature\Actions\Audit;

use App\Actions\Audit\ListAuditLogs;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class ListAuditLogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_filters_are_combined_and_to_includes_the_entire_utc_day(): void
    {
        $reader = $this->makeUser('reader', UserRole::RegistrarHead);
        $targetActor = $this->makeUser('target-actor');
        $otherActor = $this->makeUser('other-actor');
        $target = $this->makeAuditLog(
            $targetActor,
            AuditAction::SECTION_UPDATED,
            AuditableType::SECTION,
            '2026-07-10 23:59:59',
        );

        $this->makeAuditLog($targetActor, AuditAction::SECTION_CREATED, AuditableType::SECTION, '2026-07-10 12:00:00');
        $this->makeAuditLog($targetActor, AuditAction::SECTION_UPDATED, AuditableType::CURRICULUM, '2026-07-10 12:00:00');
        $this->makeAuditLog($otherActor, AuditAction::SECTION_UPDATED, AuditableType::SECTION, '2026-07-10 12:00:00');
        $this->makeAuditLog($targetActor, AuditAction::SECTION_UPDATED, AuditableType::SECTION, '2026-07-09 23:59:59');
        $this->makeAuditLog($targetActor, AuditAction::SECTION_UPDATED, AuditableType::SECTION, '2026-07-11 00:00:00');

        $filters = [
            'action' => AuditAction::SECTION_UPDATED,
            'auditable_type' => AuditableType::SECTION,
            'actor_user_id' => $targetActor->id,
            'from' => '2026-07-10',
            'to' => '2026-07-10',
            'page' => 1,
            'per_page' => 20,
        ];

        $paginator = $this->action()->execute(
            $reader,
            $filters,
            new AuditRequestContext('audit-list-filtered', '203.0.113.20'),
        );

        self::assertSame([$target->id], $this->paginatorIds($paginator));
        self::assertSame(1, $paginator->total());

        $readAudit = AuditLog::query()
            ->where('request_id', 'audit-list-filtered')
            ->sole();

        self::assertSame($reader->id, $readAudit->actor_user_id);
        self::assertSame(AuditAction::AUDIT_LOG_LIST_VIEWED, $readAudit->action);
        self::assertSame(AuditableType::AUDIT_LOG, $readAudit->auditable_type);
        self::assertNull($readAudit->auditable_id);
        self::assertNull($readAudit->before_values);
        self::assertSame($filters, $readAudit->after_values);
        self::assertNull($readAudit->reason);
        self::assertSame('203.0.113.20', $readAudit->ip_address);
    }

    public function test_it_orders_deterministically_and_eager_loads_each_actor(): void
    {
        $reader = $this->makeUser('ordered-reader', UserRole::RegistrarHead);
        $firstActor = $this->makeUser('ordered-first');
        $secondActor = $this->makeUser('ordered-second');
        $older = $this->makeAuditLog(
            $firstActor,
            AuditAction::CURRICULUM_CREATED,
            AuditableType::CURRICULUM,
            '2026-07-09 09:00:00',
        );
        $lowerTieId = $this->makeAuditLog(
            $firstActor,
            AuditAction::SECTION_CREATED,
            AuditableType::SECTION,
            '2026-07-10 09:00:00',
        );
        $higherTieId = $this->makeAuditLog(
            $secondActor,
            AuditAction::SECTION_UPDATED,
            AuditableType::SECTION,
            '2026-07-10 09:00:00',
        );

        $paginator = $this->action()->execute(
            $reader,
            [],
            new AuditRequestContext('audit-list-ordered', null),
        );

        self::assertSame(
            [$higherTieId->id, $lowerTieId->id, $older->id],
            $this->paginatorIds($paginator),
        );
        self::assertSame(3, $paginator->total());
        self::assertSame(4, AuditLog::query()->count());

        foreach ($paginator->items() as $auditLog) {
            self::assertTrue($auditLog->relationLoaded('actor'));
        }

        $readAudit = AuditLog::query()
            ->where('request_id', 'audit-list-ordered')
            ->sole();

        self::assertSame([
            'action' => null,
            'auditable_type' => null,
            'actor_user_id' => null,
            'from' => null,
            'to' => null,
            'page' => 1,
            'per_page' => 20,
        ], $readAudit->after_values);
        self::assertNotContains(
            $readAudit->id,
            $this->paginatorIds($paginator),
            'The read event created after materialization must not leak into the returned page.',
        );
    }

    public function test_it_materializes_the_requested_page_before_recording_the_read(): void
    {
        $reader = $this->makeUser('paginated-reader', UserRole::RegistrarHead);
        $actor = $this->makeUser('paginated-actor');
        $older = $this->makeAuditLog(
            $actor,
            AuditAction::CURRICULUM_CREATED,
            AuditableType::CURRICULUM,
            '2026-07-10 08:00:00',
        );
        $this->makeAuditLog(
            $actor,
            AuditAction::SECTION_CREATED,
            AuditableType::SECTION,
            '2026-07-10 09:00:00',
        );

        $paginator = $this->action()->execute(
            $reader,
            ['page' => 2, 'per_page' => 1],
            new AuditRequestContext('audit-list-page-two', '198.51.100.2'),
        );

        self::assertSame([$older->id], $this->paginatorIds($paginator));
        self::assertSame(2, $paginator->total());
        self::assertSame(2, $paginator->currentPage());
        self::assertSame(2, $paginator->lastPage());
        self::assertSame(3, AuditLog::query()->count());

        $readAudit = AuditLog::query()
            ->where('request_id', 'audit-list-page-two')
            ->sole();

        self::assertSame([
            'action' => null,
            'auditable_type' => null,
            'actor_user_id' => null,
            'from' => null,
            'to' => null,
            'page' => 2,
            'per_page' => 1,
        ], $readAudit->after_values);
    }

    public function test_a_recorder_failure_does_not_return_a_paginator_or_create_a_read_event(): void
    {
        $reader = $this->makeUser('failed-reader', UserRole::RegistrarHead);
        $this->makeAuditLog(
            $reader,
            AuditAction::SECTION_CREATED,
            AuditableType::SECTION,
            '2026-07-10 09:00:00',
        );

        try {
            $this->action()->execute(
                $reader,
                [],
                new AuditRequestContext('   ', null),
            );
            self::fail('An invalid audit context must fail the list transaction.');
        } catch (InvalidArgumentException) {
            self::assertSame(1, AuditLog::query()->count());
        }
    }

    private function action(): ListAuditLogs
    {
        return new ListAuditLogs(new AuditRecorder);
    }

    /**
     * @param  LengthAwarePaginator<int, AuditLog>  $paginator
     * @return list<int>
     */
    private function paginatorIds(LengthAwarePaginator $paginator): array
    {
        return array_values(array_map(
            static fn (AuditLog $auditLog): int => $auditLog->id,
            $paginator->items(),
        ));
    }

    private function makeUser(string $handle, UserRole $role = UserRole::Student): User
    {
        return User::create([
            'name' => 'Audit '.$handle,
            'email' => 'audit-'.$handle.'@grc.test',
            'password' => 'irrelevant-password',
            'role' => $role,
            'status' => UserStatus::Active,
        ]);
    }

    private function makeAuditLog(
        User $actor,
        string $action,
        string $auditableType,
        string $createdAt,
    ): AuditLog {
        $timestamp = CarbonImmutable::parse($createdAt, 'UTC');
        $id = DB::table('audit_logs')->insertGetId([
            'actor_user_id' => $actor->id,
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => 100,
            'before_values' => null,
            'after_values' => null,
            'reason' => null,
            'request_id' => 'fixture-'.uniqid(),
            'ip_address' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return AuditLog::query()->findOrFail($id);
    }
}
