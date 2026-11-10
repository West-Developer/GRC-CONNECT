<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\QueueTicketStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\QueueKioskAccess;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\QueueCycle;
use App\Models\QueueTicket;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

final class ClaimQueueTicketEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_a_student_cannot_claim_their_own_ticket_without_a_kiosk_token(): void
    {
        [$studentToken] = $this->makeApprovedStudent('2026-08-00001');

        $this->withToken($studentToken)->postJson('/api/v1/queue-tickets')
            ->assertForbidden();
    }

    public function test_a_student_can_claim_their_own_ticket_with_an_active_kiosk_token(): void
    {
        [$studentToken, $enrollment] = $this->makeApprovedStudent('2026-08-00001');

        $this->withToken($studentToken)
            ->withHeader(QueueKioskAccess::TOKEN_HEADER, $this->kioskToken())
            ->postJson('/api/v1/queue-tickets')
            ->assertCreated()
            ->assertJsonPath('data.ticket_number', 'Q001')
            ->assertJsonPath('data.status', 'waiting')
            ->assertJsonPath('data.enrollment_id', $enrollment->id);
        self::assertSame(AuditAction::QUEUE_TICKET_CLAIMED, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole()->action);
    }

    public function test_a_student_cannot_claim_with_a_fabricated_kiosk_token(): void
    {
        [$studentToken] = $this->makeApprovedStudent('2026-08-00008');

        $this->withToken($studentToken)
            ->withHeader(QueueKioskAccess::TOKEN_HEADER, 'fabricated-kiosk-token')
            ->postJson('/api/v1/queue-tickets')
            ->assertForbidden();
    }

    public function test_a_student_cannot_claim_with_a_revoked_kiosk_token(): void
    {
        [$studentToken] = $this->makeApprovedStudent('2026-08-00009');
        $kioskToken = $this->kioskToken();
        PersonalAccessToken::findToken($kioskToken)?->delete();

        $this->withToken($studentToken)
            ->withHeader(QueueKioskAccess::TOKEN_HEADER, $kioskToken)
            ->postJson('/api/v1/queue-tickets')
            ->assertForbidden();
    }

    public function test_a_student_cannot_claim_with_an_expired_kiosk_token(): void
    {
        [$studentToken] = $this->makeApprovedStudent('2026-08-00010');

        $this->withToken($studentToken)
            ->withHeader(QueueKioskAccess::TOKEN_HEADER, $this->kioskToken(expiresAt: CarbonImmutable::now()->subSecond()))
            ->postJson('/api/v1/queue-tickets')
            ->assertForbidden();
    }

    public function test_a_student_cannot_claim_with_a_disabled_kiosk_owner(): void
    {
        [$studentToken] = $this->makeApprovedStudent('2026-08-00011');

        $this->withToken($studentToken)
            ->withHeader(QueueKioskAccess::TOKEN_HEADER, $this->kioskToken(status: UserStatus::Disabled))
            ->postJson('/api/v1/queue-tickets')
            ->assertForbidden();
    }

    public function test_a_student_cannot_claim_with_a_student_owned_kiosk_ability_token(): void
    {
        [$studentToken] = $this->makeApprovedStudent('2026-08-00012');

        $this->withToken($studentToken)
            ->withHeader(QueueKioskAccess::TOKEN_HEADER, $this->kioskToken(role: UserRole::Student))
            ->postJson('/api/v1/queue-tickets')
            ->assertForbidden();
    }

    public function test_a_student_cannot_claim_with_a_kiosk_token_without_the_required_ability(): void
    {
        [$studentToken] = $this->makeApprovedStudent('2026-08-00013');

        $this->withToken($studentToken)
            ->withHeader(QueueKioskAccess::TOKEN_HEADER, $this->kioskToken(abilities: []))
            ->postJson('/api/v1/queue-tickets')
            ->assertForbidden();
    }

    public function test_claiming_twice_returns_the_same_ticket(): void
    {
        [$studentToken] = $this->makeApprovedStudent('2026-08-00002');
        $kioskToken = $this->kioskToken();

        $first = $this->withToken($studentToken)
            ->withHeader(QueueKioskAccess::TOKEN_HEADER, $kioskToken)
            ->postJson('/api/v1/queue-tickets');
        $second = $this->withToken($studentToken)
            ->withHeader(QueueKioskAccess::TOKEN_HEADER, $kioskToken)
            ->postJson('/api/v1/queue-tickets');

        self::assertSame($first->json('data.id'), $second->json('data.id'));
        self::assertSame(1, QueueTicket::query()->count());
    }

    public function test_a_student_with_no_pending_payment_enrollment_cannot_claim(): void
    {
        $token = $this->tokenForNewUser(UserRole::Student, 'no.enrollment@grc.test');

        $this->withToken($token)
            ->withHeader(QueueKioskAccess::TOKEN_HEADER, $this->kioskToken())
            ->postJson('/api/v1/queue-tickets')
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.student_number.0', 'No enrollment pending payment was found for this student.');
    }

    public function test_accounting_staff_can_issue_a_ticket_for_a_student_by_number(): void
    {
        [, $enrollment] = $this->makeApprovedStudent('2026-08-00003');
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'issuer@grc.test');

        $this->withToken($token)->postJson('/api/v1/queue-tickets', ['student_number' => '2026-08-00003'])
            ->assertCreated()
            ->assertJsonPath('data.enrollment_id', $enrollment->id);
    }

    public function test_accounting_staff_can_issue_a_ticket_with_a_junk_kiosk_header(): void
    {
        [, $enrollment] = $this->makeApprovedStudent('2026-08-00014');
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'junk-header-issuer@grc.test');

        $this->withToken($token)
            ->withHeader(QueueKioskAccess::TOKEN_HEADER, 'junk')
            ->postJson('/api/v1/queue-tickets', ['student_number' => '2026-08-00014'])
            ->assertCreated()
            ->assertJsonPath('data.enrollment_id', $enrollment->id);
    }

    public function test_registrar_staff_cannot_claim_for_a_student(): void
    {
        $this->makeApprovedStudent('2026-08-00004');
        $token = $this->tokenForNewUser(UserRole::RegistrarStaff, 'registrar@grc.test');

        $this->withToken($token)->postJson('/api/v1/queue-tickets', ['student_number' => '2026-08-00004'])
            ->assertForbidden();
    }

    public function test_numbering_continues_across_a_cut_off_carry_over(): void
    {
        $cycle = QueueCycle::create(['opened_on' => '2026-08-22', 'last_ticket_sequence' => 47, 'last_claimed_on' => '2026-08-22']);
        // The cycle is not drained: an earlier ticket is still waiting to
        // be served, carried over from Friday -- this is what keeps
        // ClaimQueueTicket from treating the cycle as closeable even
        // though a Manila day has passed since its last claim.
        [, $carryOverEnrollment] = $this->makeApprovedStudent('2026-08-00098');
        QueueTicket::create([
            'enrollment_id' => $carryOverEnrollment->id,
            'queue_cycle_id' => $cycle->id,
            'ticket_sequence' => 47,
            'ticket_number' => 'Q047',
            'queue_date' => '2026-08-22',
            'status' => QueueTicketStatus::Waiting,
        ]);
        [$studentToken] = $this->makeApprovedStudent('2026-08-00005');
        CarbonImmutable::setTestNow('2026-08-23 01:00:00'); // 09:00 PHT

        $this->withToken($studentToken)
            ->withHeader(QueueKioskAccess::TOKEN_HEADER, $this->kioskToken())
            ->postJson('/api/v1/queue-tickets')
            ->assertCreated()
            ->assertJsonPath('data.ticket_number', 'Q048');
        self::assertSame($cycle->id, QueueTicket::query()->where('ticket_number', 'Q048')->sole()->queue_cycle_id);
    }

    public function test_numbering_resets_once_the_cycle_is_fully_drained_on_an_earlier_date(): void
    {
        QueueCycle::create([
            'opened_on' => '2026-08-20', 'last_ticket_sequence' => 12, 'last_claimed_on' => '2026-08-20',
        ]);
        [$studentToken] = $this->makeApprovedStudent('2026-08-00006');
        CarbonImmutable::setTestNow('2026-08-23 01:00:00'); // 09:00 PHT, no outstanding tickets anywhere

        $this->withToken($studentToken)
            ->withHeader(QueueKioskAccess::TOKEN_HEADER, $this->kioskToken())
            ->postJson('/api/v1/queue-tickets')
            ->assertCreated()
            ->assertJsonPath('data.ticket_number', 'Q001');
        self::assertSame(2, QueueCycle::query()->count());
        self::assertNotNull(QueueCycle::query()->where('opened_on', '2026-08-20')->sole()->closed_at);
    }

    public function test_queue_date_uses_manila_time_not_utc(): void
    {
        [$studentToken] = $this->makeApprovedStudent('2026-08-00007');
        // 23:30 UTC on the 22nd is 07:30 on the 23rd in Manila.
        CarbonImmutable::setTestNow('2026-08-22 23:30:00');

        $this->withToken($studentToken)
            ->withHeader(QueueKioskAccess::TOKEN_HEADER, $this->kioskToken())
            ->postJson('/api/v1/queue-tickets')
            ->assertCreated();

        self::assertSame('2026-08-23', QueueTicket::query()->sole()->queue_date->toDateString());
    }

    /**
     * @return array{string, Enrollment}
     */
    private function makeApprovedStudent(string $studentNumber): array
    {
        $term = AcademicTerm::query()->firstOrCreate(
            ['school_year' => '2026-2027', 'semester' => '1st'],
            ['status' => AcademicTermStatus::SemesterOngoing],
        );
        $program = Program::create(['code' => 'BSCS-'.$studentNumber, 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
        $user = User::create([
            'name' => 'Claim Test Student', 'email' => 'claim.'.$studentNumber.'@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        $student = StudentProfile::create([
            'user_id' => $user->id, 'student_number' => $studentNumber,
            'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted, 'academic_standing' => AcademicStanding::Good,
        ]);
        $enrollment = Enrollment::create([
            'student_id' => $student->id, 'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::PendingPayment, 'total_units' => 3,
        ]);

        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => self::PASSWORD,
        ])->json('data.token');

        return [$token, $enrollment];
    }

    private function tokenForNewUser(UserRole $role, string $email): string
    {
        User::create([
            'name' => 'Test '.$role->value, 'email' => $email,
            'password' => self::PASSWORD, 'role' => $role, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    /**
     * @param  list<string>  $abilities
     */
    private function kioskToken(
        array $abilities = [QueueKioskAccess::TOKEN_ABILITY],
        ?CarbonImmutable $expiresAt = null,
        UserRole $role = UserRole::QueueKiosk,
        UserStatus $status = UserStatus::Active,
    ): string {
        $user = User::create([
            'name' => 'Queue Kiosk',
            'email' => 'queue-kiosk-'.User::query()->count().'@grc.test',
            'password' => self::PASSWORD,
            'role' => $role,
            'status' => $status,
        ]);

        return $user->createToken('queue-kiosk-test', $abilities, $expiresAt)->plainTextToken;
    }
}
