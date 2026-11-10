<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Http\Middleware\AssignRequestId;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AuditLogsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_requests_receive_the_standard_unauthenticated_response(): void
    {
        $response = $this
            ->withHeader(AssignRequestId::HEADER, 'anonymous-audit-list')
            ->getJson('/api/v1/audit-logs');

        $response
            ->assertUnauthorized()
            ->assertHeader(AssignRequestId::HEADER, 'anonymous-audit-list')
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('error.request_id', 'anonymous-audit-list');
    }

    #[DataProvider('nonRegistrarRoleProvider')]
    public function test_every_non_registrar_head_role_is_forbidden(UserRole $role): void
    {
        $user = $this->makeUser('forbidden-'.$role->value, $role);
        $token = $this->tokenFor($user);

        $response = $this
            ->withToken($token)
            ->withHeader(AssignRequestId::HEADER, 'audit-forbidden-'.$role->value)
            ->getJson('/api/v1/audit-logs');

        $response
            ->assertForbidden()
            ->assertHeader(AssignRequestId::HEADER, 'audit-forbidden-'.$role->value)
            ->assertJsonPath('error.code', 'FORBIDDEN')
            ->assertJsonPath('error.request_id', 'audit-forbidden-'.$role->value);

        self::assertSame(0, AuditLog::query()->count());
    }

    /**
     * @return iterable<string, array{UserRole}>
     */
    public static function nonRegistrarRoleProvider(): iterable
    {
        foreach (UserRole::cases() as $role) {
            if ($role !== UserRole::RegistrarHead && $role !== UserRole::SuperAdmin) {
                yield $role->value => [$role];
            }
        }
    }

    public function test_super_admin_receives_ok_response_for_audit_logs_and_actors(): void
    {
        $superAdmin = $this->makeUser('super-admin-reader', UserRole::SuperAdmin);
        $token = $this->tokenFor($superAdmin);

        $response = $this
            ->withToken($token)
            ->getJson('/api/v1/audit-logs');

        $response->assertOk();

        $actorsResponse = $this
            ->withToken($token)
            ->getJson('/api/v1/audit-logs/actors');

        $actorsResponse->assertOk();
    }

    public function test_registrar_head_receives_the_exact_resource_pagination_and_private_response_shape(): void
    {
        $reader = $this->makeUser('successful-reader', UserRole::RegistrarHead);
        $actor = $this->makeUser('resource-actor', UserRole::ProgramChair);
        $token = $this->tokenFor($reader);
        $tieTime = '2026-07-10 09:00:00';

        for ($index = 1; $index <= 19; $index++) {
            $this->makeAuditLog(
                $actor,
                AuditAction::CURRICULUM_CREATED,
                AuditableType::CURRICULUM,
                CarbonImmutable::parse($tieTime, 'UTC')->subMinutes($index)->format('Y-m-d H:i:s'),
            );
        }

        $lowerTieId = $this->makeAuditLog(
            $actor,
            AuditAction::SECTION_CREATED,
            AuditableType::SECTION,
            $tieTime,
            501,
            ['status' => 'draft'],
            ['status' => 'published'],
            'Approved fixture.',
            'fixture-resource-lower',
            '203.0.113.41',
        );
        $higherTieId = $this->makeAuditLog(
            $actor,
            AuditAction::SECTION_UPDATED,
            AuditableType::SECTION,
            $tieTime,
            502,
            ['capacity' => 30],
            ['capacity' => 35],
            'Capacity adjusted.',
            'fixture-resource-higher',
            '2001:db8::41',
        );

        $response = $this
            ->withToken($token)
            ->withHeader(AssignRequestId::HEADER, 'audit-list-response-id')
            ->getJson('/api/v1/audit-logs');

        $response
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader(AssignRequestId::HEADER, 'audit-list-response-id')
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.type', 'audit_log')
            ->assertJsonPath('data.0.id', $higherTieId->id)
            ->assertJsonPath('data.1.id', $lowerTieId->id)
            ->assertJsonPath('data.0.actor_user_id', $actor->id)
            ->assertJsonPath('data.0.actor_role', UserRole::ProgramChair->value)
            ->assertJsonPath('data.0.actor_role_label', UserRole::ProgramChair->label())
            ->assertJsonPath('data.0.action', AuditAction::SECTION_UPDATED)
            ->assertJsonPath('data.0.auditable_type', AuditableType::SECTION)
            ->assertJsonPath('data.0.auditable_id', 502)
            ->assertJsonPath('data.0.before_values.capacity', 30)
            ->assertJsonPath('data.0.after_values.capacity', 35)
            ->assertJsonPath('data.0.reason', 'Capacity adjusted.')
            ->assertJsonPath('data.0.request_id', 'fixture-resource-higher')
            ->assertJsonPath('data.0.ip_address', '2001:db8::41')
            ->assertJsonPath('data.0.created_at', '2026-07-10T09:00:00Z')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 21)
            ->assertJsonStructure([
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);

        self::assertSame([
            'type',
            'id',
            'actor_user_id',
            'actor_name',
            'actor_role',
            'actor_role_label',
            'acting_role',
            'acting_role_label',
            'acting_college',
            'action',
            'auditable_type',
            'auditable_id',
            'before_values',
            'after_values',
            'changes',
            'reason',
            'request_id',
            'ip_address',
            'created_at',
        ], array_keys($response->json('data.0')));
        // The Registrar Head sees who acted (stakeholder Doc 14), never their email.
        $response->assertJsonPath('data.0.actor_name', $actor->name);
        $response->assertDontSee($actor->email);
        $response->assertJsonPath('data.0.changes.0', [
            'field' => 'capacity',
            'label' => 'Seats',
            'old' => 30,
            'new' => 35,
            'changed' => true,
        ]);

        self::assertSame(22, AuditLog::query()->count());
        self::assertDatabaseHas('audit_logs', [
            'actor_user_id' => $reader->id,
            'action' => AuditAction::AUDIT_LOG_LIST_VIEWED,
            'request_id' => 'audit-list-response-id',
        ]);
    }

    public function test_changes_name_the_professor_mark_unchanged_fields_and_hide_name_like_fields(): void
    {
        $reader = $this->makeUser('changes-reader', UserRole::RegistrarHead);
        $actor = $this->makeUser('changes-actor', UserRole::ProgramChair);
        $oldProfessor = $this->makeUser('old-professor', UserRole::Faculty);
        $newProfessor = $this->makeUser('new-professor', UserRole::Faculty);
        $this->makeAuditLog(
            $actor,
            AuditAction::SECTION_UPDATED,
            AuditableType::SECTION,
            '2026-07-10 09:00:00',
            7,
            ['professor_id' => $oldProfessor->id, 'room' => 'R101', 'student_name' => 'Real Person'],
            ['professor_id' => $newProfessor->id, 'room' => 'R101', 'student_name' => 'Other Person'],
        );

        $response = $this->withToken($this->tokenFor($reader))
            ->getJson('/api/v1/audit-logs?action='.AuditAction::SECTION_UPDATED);

        $response->assertOk();
        $byField = collect($response->json('data.0.changes'))->keyBy('field');
        self::assertSame('Professor', $byField['professor_id']['label']);
        self::assertSame($oldProfessor->name, $byField['professor_id']['old']);
        self::assertSame($newProfessor->name, $byField['professor_id']['new']);
        self::assertTrue($byField['professor_id']['changed']);
        self::assertFalse($byField['room']['changed']);
        self::assertSame('Hidden', $byField['student_name']['old']);
        self::assertSame('Hidden', $byField['student_name']['new']);
        // The readable change list never carries a name-like value.
        self::assertStringNotContainsString('Real Person', (string) json_encode($response->json('data.0.changes')));
        self::assertStringNotContainsString('Other Person', (string) json_encode($response->json('data.0.changes')));
    }

    public function test_actor_summaries_group_entries_per_user_newest_activity_first(): void
    {
        $reader = $this->makeUser('actors-reader', UserRole::RegistrarHead);
        $busy = $this->makeUser('busy-actor', UserRole::ProgramChair);
        $recent = $this->makeUser('recent-actor', UserRole::Dean);
        $this->makeAuditLog($busy, AuditAction::SECTION_CREATED, AuditableType::SECTION, '2026-07-01 09:00:00');
        $this->makeAuditLog($busy, AuditAction::SECTION_UPDATED, AuditableType::SECTION, '2026-07-02 09:00:00');
        $this->makeAuditLog($busy, AuditAction::SECTION_UPDATED, AuditableType::SECTION, '2026-07-03 09:00:00');
        $this->makeAuditLog($recent, AuditAction::SECTION_UPDATED, AuditableType::SECTION, '2026-07-10 09:00:00');

        $response = $this->withToken($this->tokenFor($reader))
            ->withHeader(AssignRequestId::HEADER, 'audit-actors-response-id')
            ->getJson('/api/v1/audit-logs/actors');

        $response
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.type', 'audit_actor')
            ->assertJsonPath('data.0.actor_user_id', $recent->id)
            ->assertJsonPath('data.0.actor_name', $recent->name)
            ->assertJsonPath('data.0.actor_role', UserRole::Dean->value)
            ->assertJsonPath('data.0.entries_count', 1)
            ->assertJsonPath('data.0.last_activity_at', '2026-07-10T09:00:00Z')
            ->assertJsonPath('data.1.actor_user_id', $busy->id)
            ->assertJsonPath('data.1.entries_count', 3)
            ->assertJsonPath('data.1.last_activity_at', '2026-07-03T09:00:00Z');
        $response->assertDontSee($busy->email);
        self::assertSame([
            'type',
            'actor_user_id',
            'actor_name',
            'actor_role',
            'actor_role_label',
            'entries_count',
            'last_activity_at',
        ], array_keys($response->json('data.0')));

        // Reading the summary is itself audited, like the entry list.
        self::assertDatabaseHas('audit_logs', [
            'actor_user_id' => $reader->id,
            'action' => AuditAction::AUDIT_LOG_LIST_VIEWED,
            'request_id' => 'audit-actors-response-id',
        ]);
    }

    public function test_actor_summaries_respect_the_action_and_date_filters(): void
    {
        $reader = $this->makeUser('actors-filter-reader', UserRole::RegistrarHead);
        $one = $this->makeUser('filter-one', UserRole::ProgramChair);
        $two = $this->makeUser('filter-two', UserRole::Dean);
        $this->makeAuditLog($one, AuditAction::SECTION_CREATED, AuditableType::SECTION, '2026-07-01 09:00:00');
        $this->makeAuditLog($two, AuditAction::SECTION_UPDATED, AuditableType::SECTION, '2026-07-10 09:00:00');

        $byAction = $this->withToken($this->tokenFor($reader))
            ->getJson('/api/v1/audit-logs/actors?action='.AuditAction::SECTION_CREATED);
        $byAction->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.actor_user_id', $one->id);
    }

    #[DataProvider('nonRegistrarRoleProvider')]
    public function test_actor_summaries_are_forbidden_to_every_other_role(UserRole $role): void
    {
        $user = $this->makeUser('actors-forbidden-'.$role->value, $role);

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/audit-logs/actors')
            ->assertForbidden();
    }

    public function test_action_filter_is_applied(): void
    {
        $reader = $this->makeUser('action-filter-reader', UserRole::RegistrarHead);
        $actor = $this->makeUser('action-filter-actor');
        $token = $this->tokenFor($reader);
        $target = $this->makeAuditLog($actor, AuditAction::SECTION_CREATED, AuditableType::SECTION, '2026-07-10 09:00:00');
        $this->makeAuditLog($actor, AuditAction::CURRICULUM_CREATED, AuditableType::CURRICULUM, '2026-07-10 10:00:00');

        $this->withToken($token)
            ->getJson('/api/v1/audit-logs?action='.AuditAction::SECTION_CREATED)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_auditable_type_filter_is_applied(): void
    {
        $reader = $this->makeUser('type-filter-reader', UserRole::RegistrarHead);
        $actor = $this->makeUser('type-filter-actor');
        $token = $this->tokenFor($reader);
        $target = $this->makeAuditLog($actor, AuditAction::SECTION_CREATED, AuditableType::SECTION, '2026-07-10 09:00:00');
        $this->makeAuditLog($actor, AuditAction::CURRICULUM_CREATED, AuditableType::CURRICULUM, '2026-07-10 10:00:00');

        $this->withToken($token)
            ->getJson('/api/v1/audit-logs?auditable_type='.AuditableType::SECTION)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_actor_user_id_filter_is_applied(): void
    {
        $reader = $this->makeUser('actor-filter-reader', UserRole::RegistrarHead);
        $targetActor = $this->makeUser('actor-filter-target');
        $otherActor = $this->makeUser('actor-filter-other');
        $token = $this->tokenFor($reader);
        $target = $this->makeAuditLog($targetActor, AuditAction::SECTION_CREATED, AuditableType::SECTION, '2026-07-10 09:00:00');
        $this->makeAuditLog($otherActor, AuditAction::SECTION_CREATED, AuditableType::SECTION, '2026-07-10 10:00:00');

        $this->withToken($token)
            ->getJson('/api/v1/audit-logs?actor_user_id='.$targetActor->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_from_filter_is_inclusive_from_the_start_of_its_utc_date(): void
    {
        $reader = $this->makeUser('from-filter-reader', UserRole::RegistrarHead);
        $actor = $this->makeUser('from-filter-actor');
        $token = $this->tokenFor($reader);
        $target = $this->makeAuditLog($actor, AuditAction::SECTION_CREATED, AuditableType::SECTION, '2026-07-10 00:00:00');
        $this->makeAuditLog($actor, AuditAction::SECTION_CREATED, AuditableType::SECTION, '2026-07-09 23:59:59');

        $this->withToken($token)
            ->getJson('/api/v1/audit-logs?from=2026-07-10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_to_filter_is_inclusive_through_the_end_of_its_utc_date(): void
    {
        $reader = $this->makeUser('to-filter-reader', UserRole::RegistrarHead);
        $actor = $this->makeUser('to-filter-actor');
        $token = $this->tokenFor($reader);
        $target = $this->makeAuditLog($actor, AuditAction::SECTION_CREATED, AuditableType::SECTION, '2026-07-10 23:59:59');
        $this->makeAuditLog($actor, AuditAction::SECTION_CREATED, AuditableType::SECTION, '2026-07-11 00:00:00');

        $this->withToken($token)
            ->getJson('/api/v1/audit-logs?to=2026-07-10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_page_size_page_number_and_filter_are_preserved_in_pagination(): void
    {
        $reader = $this->makeUser('pagination-reader', UserRole::RegistrarHead);
        $actor = $this->makeUser('pagination-actor');
        $token = $this->tokenFor($reader);

        for ($index = 1; $index <= 3; $index++) {
            $this->makeAuditLog(
                $actor,
                AuditAction::SECTION_CREATED,
                AuditableType::SECTION,
                '2026-07-10 0'.$index.':00:00',
            );
        }

        $response = $this->withToken($token)
            ->getJson('/api/v1/audit-logs?action='.AuditAction::SECTION_CREATED.'&per_page=1&page=2');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 3);

        $nextLink = $response->json('links.next');
        self::assertIsString($nextLink);
        parse_str((string) parse_url($nextLink, PHP_URL_QUERY), $query);
        self::assertSame([
            'action' => AuditAction::SECTION_CREATED,
            'per_page' => '1',
            'page' => '3',
        ], $query);
    }

    public function test_per_page_accepts_the_inclusive_one_to_one_hundred_bounds(): void
    {
        $reader = $this->makeUser('page-bounds-reader', UserRole::RegistrarHead);
        $token = $this->tokenFor($reader);

        $this->withToken($token)
            ->getJson('/api/v1/audit-logs?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1);

        $this->withToken($token)
            ->getJson('/api/v1/audit-logs?per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_invalid_query_values_receive_the_standard_validation_response(
        string $query,
        string $field,
    ): void {
        $reader = $this->makeUser('invalid-'.md5($query), UserRole::RegistrarHead);
        $token = $this->tokenFor($reader);

        $response = $this
            ->withToken($token)
            ->withHeader(AssignRequestId::HEADER, 'audit-invalid-'.md5($query))
            ->getJson('/api/v1/audit-logs?'.$query);

        $response
            ->assertUnprocessable()
            ->assertHeader(AssignRequestId::HEADER, 'audit-invalid-'.md5($query))
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.message', 'The submitted data is invalid.')
            ->assertJsonPath('error.request_id', 'audit-invalid-'.md5($query))
            ->assertJsonStructure(['error' => ['errors' => [$field]]]);

        self::assertSame(0, AuditLog::query()->count());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidQueryProvider(): iterable
    {
        yield 'unknown action' => ['action=unknown.action', 'action'];
        yield 'unknown auditable type' => ['auditable_type=unknown_type', 'auditable_type'];
        yield 'nonexistent actor' => ['actor_user_id=999999', 'actor_user_id'];
        yield 'malformed from date' => ['from=not-a-date', 'from'];
        yield 'malformed to date' => ['to=not-a-date', 'to'];
        yield 'to before from' => ['from=2026-07-11&to=2026-07-10', 'to'];
        yield 'page below one' => ['page=0', 'page'];
        yield 'per page below one' => ['per_page=0', 'per_page'];
        yield 'per page above one hundred' => ['per_page=101', 'per_page'];
    }

    private function makeUser(string $handle, UserRole $role = UserRole::Student): User
    {
        return User::create([
            'name' => 'Private name '.$handle,
            'email' => $handle.'@private.grc.test',
            'password' => 'irrelevant-password',
            'role' => $role,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('audit-log-test')->plainTextToken;
    }

    /**
     * @param  ?array<string, mixed>  $beforeValues
     * @param  ?array<string, mixed>  $afterValues
     */
    private function makeAuditLog(
        User $actor,
        string $action,
        string $auditableType,
        string $createdAt,
        ?int $auditableId = 100,
        ?array $beforeValues = null,
        ?array $afterValues = null,
        ?string $reason = null,
        ?string $requestId = null,
        ?string $ipAddress = null,
    ): AuditLog {
        $timestamp = CarbonImmutable::parse($createdAt, 'UTC');
        $id = DB::table('audit_logs')->insertGetId([
            'actor_user_id' => $actor->id,
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'before_values' => $beforeValues === null ? null : json_encode($beforeValues, JSON_THROW_ON_ERROR),
            'after_values' => $afterValues === null ? null : json_encode($afterValues, JSON_THROW_ON_ERROR),
            'reason' => $reason,
            'request_id' => $requestId ?? 'fixture-'.uniqid(),
            'ip_address' => $ipAddress,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return AuditLog::query()->findOrFail($id);
    }

    public function test_audit_log_captures_acting_context_when_action_taken_by_super_admin(): void
    {
        $superAdmin = $this->makeUser('super-admin-actor', UserRole::SuperAdmin);
        $token = $this->tokenFor($superAdmin);

        // Switch to dean with college ccs
        $this->withToken($token)
            ->putJson('/api/v1/super-admin/acting-context', [
                'role' => 'dean',
                'college' => 'ccs',
            ])
            ->assertOk();

        auth()->forgetGuards();

        // Perform an exit action while acting as Dean CCS
        $this->withToken($token)
            ->deleteJson('/api/v1/super-admin/acting-context')
            ->assertOk();

        auth()->forgetGuards();

        // Query audit logs as super admin
        $response = $this->withToken($token)
            ->getJson('/api/v1/audit-logs');

        $response->assertOk();

        // The most recent log (exit) was executed while acting as Dean (CCS)
        $response->assertJsonPath('data.0.actor_role', 'super_admin')
            ->assertJsonPath('data.0.acting_role', 'dean')
            ->assertJsonPath('data.0.acting_role_label', 'Dean')
            ->assertJsonPath('data.0.acting_college', 'ccs');
    }
}
