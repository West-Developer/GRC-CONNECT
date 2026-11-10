<?php

namespace Tests\Feature\Actions\Audit;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AuditRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_the_exact_safe_audit_values(): void
    {
        $actor = $this->makeActor();
        $beforeValues = ['name' => 'BSCS', 'status' => 'draft'];
        $afterValues = ['name' => 'BSCS', 'status' => 'published'];
        $context = new AuditRequestContext('audit-request-001', '2001:db8::10');

        $auditLog = (new AuditRecorder)->record(
            $actor,
            AuditAction::SCHEDULE_PROPOSAL_PUBLISHED,
            'schedule_proposal',
            45,
            $beforeValues,
            $afterValues,
            'Approved after review.',
            $context,
        );

        self::assertSame($actor->id, $auditLog->actor_user_id);
        self::assertSame(AuditAction::SCHEDULE_PROPOSAL_PUBLISHED, $auditLog->action);
        self::assertSame('schedule_proposal', $auditLog->auditable_type);
        self::assertSame(45, $auditLog->auditable_id);
        self::assertSame($beforeValues, $auditLog->before_values);
        self::assertSame($afterValues, $auditLog->after_values);
        self::assertSame('Approved after review.', $auditLog->reason);
        self::assertSame('audit-request-001', $auditLog->request_id);
        self::assertSame('2001:db8::10', $auditLog->ip_address);
        self::assertDatabaseHas('audit_logs', [
            'id' => $auditLog->id,
            'actor_user_id' => $actor->id,
            'action' => AuditAction::SCHEDULE_PROPOSAL_PUBLISHED,
            'auditable_type' => 'schedule_proposal',
            'auditable_id' => 45,
            'reason' => 'Approved after review.',
            'request_id' => 'audit-request-001',
            'ip_address' => '2001:db8::10',
        ]);
    }

    public function test_it_persists_approved_nullable_values(): void
    {
        $auditLog = (new AuditRecorder)->record(
            $this->makeActor(),
            AuditAction::AUDIT_LOG_LIST_VIEWED,
            'audit_log',
            null,
            null,
            null,
            null,
            new AuditRequestContext('audit-request-nullable', null),
        );

        self::assertNull($auditLog->auditable_id);
        self::assertNull($auditLog->before_values);
        self::assertNull($auditLog->after_values);
        self::assertNull($auditLog->reason);
        self::assertNull($auditLog->ip_address);
        self::assertDatabaseHas('audit_logs', [
            'id' => $auditLog->id,
            'auditable_id' => null,
            'before_values' => null,
            'after_values' => null,
            'reason' => null,
            'ip_address' => null,
        ]);
    }

    public function test_it_rejects_an_unknown_action(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->record(action: 'schedule.unknown');
    }

    #[DataProvider('blankRequiredValueProvider')]
    public function test_it_rejects_blank_required_values(
        string $action,
        string $auditableType,
        ?string $reason,
        AuditRequestContext $context,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        $this->record($action, $auditableType, $reason, $context);
    }

    /**
     * @return iterable<string, array{string, string, ?string, AuditRequestContext}>
     */
    public static function blankRequiredValueProvider(): iterable
    {
        yield 'blank action' => ['', 'section', null, new AuditRequestContext('audit-request-blank-action', null)];
        yield 'blank auditable type' => [AuditAction::SECTION_CREATED, '   ', null, new AuditRequestContext('audit-request-blank-type', null)];
        yield 'blank request ID' => [AuditAction::SECTION_CREATED, 'section', null, new AuditRequestContext(' ', null)];
        yield 'blank non-null reason' => [AuditAction::SECTION_CREATED, 'section', "\t\n", new AuditRequestContext('audit-request-blank-reason', null)];
    }

    /**
     * @param  array<string, mixed>  $beforeValues
     * @param  array<string, mixed>  $afterValues
     */
    #[DataProvider('forbiddenPayloadProvider')]
    public function test_it_recursively_rejects_secret_and_contact_payload_keys(array $beforeValues, array $afterValues): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AuditRecorder)->record(
            $this->makeActor(),
            AuditAction::SECTION_UPDATED,
            'section',
            15,
            $beforeValues,
            $afterValues,
            null,
            new AuditRequestContext('audit-request-forbidden-payload', '203.0.113.4'),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function forbiddenPayloadProvider(): iterable
    {
        yield 'nested password confirmation' => [['profile' => ['password_confirmation' => 'unsafe']], []];
        yield 'nested contact email' => [[], ['actor' => ['email' => 'unsafe@grc.test']]];
        yield 'token fragment after normalizing a key' => [['api-token' => 'unsafe'], []];
        yield 'secret fragment after normalizing a key' => [[], ['auth secret' => 'unsafe']];
        yield 'exact phone contact key' => [['phone' => '+639171234567'], []];
        yield 'exact mobile contact key' => [[], ['mobile' => '+639171234567']];
        yield 'exact address contact key' => [['address' => 'Caloocan City'], []];
        yield 'compound actor email contact key' => [['actor_email' => 'unsafe@grc.test'], []];
        yield 'compound home phone contact key' => [[], ['home_phone' => '+639171234567']];
        yield 'compound mobile number contact key' => [['mobile_number' => '+639171234567'], []];
        yield 'compound mailing address contact key' => [[], ['mailing_address' => 'Caloocan City']];
    }

    public function test_it_does_not_open_or_commit_a_transaction(): void
    {
        $transactionEvents = [];
        Event::listen(TransactionBeginning::class, static function () use (&$transactionEvents): void {
            $transactionEvents[] = TransactionBeginning::class;
        });
        Event::listen(TransactionCommitted::class, static function () use (&$transactionEvents): void {
            $transactionEvents[] = TransactionCommitted::class;
        });

        $auditLog = $this->record();

        self::assertDatabaseHas('audit_logs', ['id' => $auditLog->id]);
        self::assertSame([], $transactionEvents);
    }

    private function record(
        string $action = AuditAction::SECTION_CREATED,
        string $auditableType = 'section',
        ?string $reason = null,
        ?AuditRequestContext $context = null,
    ): AuditLog {
        return (new AuditRecorder)->record(
            $this->makeActor(),
            $action,
            $auditableType,
            12,
            ['name' => 'BSCS'],
            ['name' => 'BSCS', 'status' => 'published'],
            $reason,
            $context ?? new AuditRequestContext('audit-request-default', '203.0.113.3'),
        );
    }

    private function makeActor(): User
    {
        return User::create([
            'name' => 'Audit Recorder Actor',
            'email' => 'audit-recorder-'.uniqid().'@grc.test',
            'password' => 'irrelevant-password',
            'role' => UserRole::RegistrarHead,
            'status' => UserStatus::Active,
        ]);
    }
}
