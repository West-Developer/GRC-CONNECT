<?php

namespace Tests\Feature\Actions\Organization;

use App\Actions\Organization\CreateAcademicTerm;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\AcademicTerm;
use App\Models\AcademicTermCollegeWorkflow;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class CreateAcademicTermAuditTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_creating_a_term_records_the_exact_safe_audit_event(): void
    {
        $registrarHead = $this->makeUser(UserRole::RegistrarHead, 'term.create@grc.test');
        $token = $this->tokenFor('term.create@grc.test');

        $response = $this->withHeader('X-Request-ID', 'term-create-request')
            ->withToken($token)
            ->postJson('/api/v1/academic-terms', $this->validPayload());

        $response->assertCreated();
        $termId = (int) $response->json('data.id');

        self::assertSame(4, AcademicTermCollegeWorkflow::where('academic_term_id', $termId)->count());

        $audit = AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole();

        self::assertSame(AuditAction::ACADEMIC_TERM_CREATED, $audit->action);
        self::assertSame(AuditableType::ACADEMIC_TERM, $audit->auditable_type);
        self::assertSame($termId, $audit->auditable_id);
        self::assertSame($registrarHead->id, $audit->actor_user_id);
        self::assertNull($audit->before_values);
        // `starts_at`, `ends_at`, and `grading_deadline_at` stay null even
        // though `validPayload()` sends them: `AcademicTermController::store`
        // hands `CreateAcademicTerm` an explicit five-key array rather than
        // the whole request, so unlisted fields cannot be mass-assigned.
        // Asserting the nulls is what proves that allow-list still holds.
        self::assertSame([
            'school_year' => '2028-2029',
            'semester' => '1st',
            'starts_at' => null,
            'ends_at' => null,
            'enrollment_opens_at' => '2028-07-01T00:00:00.000000Z',
            'enrollment_closes_at' => '2028-07-15T00:00:00.000000Z',
            'add_drop_deadline_at' => '2028-07-20T00:00:00.000000Z',
            'grading_deadline_at' => null,
            'status' => 'draft',
        ], $audit->after_values);
        self::assertSame('term-create-request', $audit->request_id);
        self::assertSame('127.0.0.1', $audit->ip_address);
    }

    public function test_rejected_creation_requests_do_not_create_audit_rows(): void
    {
        $this->makeUser(UserRole::RegistrarHead, 'term.reject@grc.test');
        $token = $this->tokenFor('term.reject@grc.test');

        // Missing required fields entirely -> 422, no Action ever runs.
        $this->withToken($token)
            ->postJson('/api/v1/academic-terms', [])
            ->assertUnprocessable();

        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_forbidden_creation_requests_do_not_create_audit_rows(): void
    {
        $this->makeUser(UserRole::ProgramChair, 'term.forbidden@grc.test');
        $token = $this->tokenFor('term.forbidden@grc.test');

        $this->withToken($token)
            ->postJson('/api/v1/academic-terms', $this->validPayload())
            ->assertForbidden();

        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_audit_creation_failure_rolls_back_the_term_creation(): void
    {
        $registrarHead = $this->makeUser(UserRole::RegistrarHead, 'term.rollback@grc.test');
        $context = new AuditRequestContext('term-rollback-request', '198.51.100.20');

        AuditLog::creating(static function (): never {
            throw new RuntimeException('Injected audit write failure.');
        });

        try {
            app(CreateAcademicTerm::class)->execute($registrarHead, [
                'school_year' => '2029-2030',
                'semester' => '1st',
                'starts_at' => '2029-08-01T00:00:00Z',
                'ends_at' => '2029-12-15T00:00:00Z',
                'enrollment_opens_at' => '2029-07-01T00:00:00Z',
                'enrollment_closes_at' => '2029-07-15T00:00:00Z',
                'add_drop_deadline_at' => '2029-07-20T00:00:00Z',
                'grading_deadline_at' => '2029-12-20T00:00:00Z',
            ], $context);
            self::fail('The injected audit write failure must escape the action transaction.');
        } catch (RuntimeException $exception) {
            self::assertSame('Injected audit write failure.', $exception->getMessage());
        } finally {
            AuditLog::flushEventListeners();
            AuditLog::clearBootedModels();
        }

        self::assertSame(0, AcademicTerm::query()->count());
        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'school_year' => '2028-2029',
            'semester' => '1st',
            'starts_at' => '2028-08-01T00:00:00Z',
            'ends_at' => '2028-12-15T00:00:00Z',
            'enrollment_opens_at' => '2028-07-01T00:00:00Z',
            'enrollment_closes_at' => '2028-07-15T00:00:00Z',
            'add_drop_deadline_at' => '2028-07-20T00:00:00Z',
            'grading_deadline_at' => '2028-12-20T00:00:00Z',
        ], $overrides);
    }

    private function makeUser(UserRole $role, string $email): User
    {
        return User::create([
            'name' => 'Audit '.$role->value,
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);
    }

    private function tokenFor(string $email): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->json('data.token');
    }
}
