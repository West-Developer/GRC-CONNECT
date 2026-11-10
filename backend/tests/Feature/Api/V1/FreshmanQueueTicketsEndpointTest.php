<?php

namespace Tests\Feature\Api\V1;

use App\Actions\Enrollment\IssueFreshmanQueueTicket;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\IssuedFreshmanQueueTicket;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\StudentType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Notifications\NotificationType;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\Notification;
use App\Models\Program;
use App\Models\QueueCycle;
use App\Models\QueueTicket;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The payment queue now also holds freshmen who have no enrollment yet: their
 * ticket hangs off the student profile. The Cashier's list, the transitions,
 * the cut-off and the drain rule must all cope with a ticket that has no
 * enrollment.
 */
final class FreshmanQueueTicketsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_the_cashier_list_includes_a_freshman_ticket_flagged_as_a_freshman(): void
    {
        $profile = $this->freshman('2026-10-00001');
        $this->issueTicket($profile);
        $token = $this->cashierToken();

        $this->withToken($token)->getJson('/api/v1/queue-tickets?cycle=open')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ticket_number', 'Q001')
            ->assertJsonPath('data.0.enrollment_id', null)
            ->assertJsonPath('data.0.student_id', $profile->id)
            ->assertJsonPath('data.0.student_number', '2026-10-00001')
            ->assertJsonPath('data.0.is_freshman', true);
    }

    public function test_a_ticket_from_a_kiosk_claim_is_not_flagged_as_a_freshman(): void
    {
        $enrollment = $this->approvedEnrollment('2026-08-00007');
        QueueTicket::create([
            'enrollment_id' => $enrollment->id, 'queue_cycle_id' => $this->cycle()->id, 'ticket_sequence' => 1,
            'ticket_number' => 'Q001', 'queue_date' => now()->toDateString(), 'status' => 'waiting',
        ]);

        $this->withToken($this->cashierToken())->getJson('/api/v1/queue-tickets?cycle=open')
            ->assertOk()
            ->assertJsonPath('data.0.is_freshman', false)
            ->assertJsonPath('data.0.student_number', '2026-08-00007')
            ->assertJsonPath('data.0.student_id', $enrollment->student_id);
    }

    public function test_the_cashier_can_call_and_finish_a_freshman_ticket(): void
    {
        $this->issueTicket($this->freshman('2026-10-00001'));
        $ticket = QueueTicket::query()->sole();
        $token = $this->cashierToken();

        $this->withToken($token)->patchJson("/api/v1/queue-tickets/{$ticket->id}", ['action' => 'serve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'serving')
            ->assertJsonPath('data.is_freshman', true);
        $this->withToken($token)->patchJson("/api/v1/queue-tickets/{$ticket->id}", ['action' => 'announce'])
            ->assertOk();
        $this->withToken($token)->patchJson("/api/v1/queue-tickets/{$ticket->id}", ['action' => 'complete'])
            ->assertOk()
            ->assertJsonPath('data.status', 'served');
    }

    public function test_cutting_off_the_queue_notifies_a_waiting_freshman_too(): void
    {
        $profile = $this->freshman('2026-10-00001');
        $this->issueTicket($profile);

        $this->withToken($this->cashierToken())->postJson('/api/v1/queue-cycle/cut-off')->assertOk();

        self::assertSame(1, Notification::query()
            ->where('user_id', $profile->user_id)
            ->where('type', NotificationType::QueueCycleCutOff)
            ->count());
    }

    public function test_a_cycle_with_only_an_unpaid_freshman_waiting_is_not_drained(): void
    {
        $this->issueTicket($this->freshman('2026-10-00001'));

        self::assertFalse(QueueCycle::query()->sole()->isDrained());
    }

    public function test_the_next_day_does_not_reset_numbering_while_a_freshman_is_still_waiting(): void
    {
        CarbonImmutable::setTestNow('2026-10-04 01:00:00'); // 09:00 PHT
        $this->issueTicket($this->freshman('2026-10-00001'));
        $second = $this->freshman('2026-10-00002');

        CarbonImmutable::setTestNow('2026-10-05 01:00:00'); // the next Manila day, the first freshman never showed up
        $issued = $this->issueTicket($second);

        self::assertSame('Q002', $issued->ticket->ticket_number);
        self::assertSame(1, QueueCycle::query()->count());
    }

    public function test_a_cycle_drains_once_its_only_freshman_has_paid(): void
    {
        $profile = $this->freshman('2026-10-00001');
        $this->issueTicket($profile);
        $profile->forceFill(['freshman_payment_confirmed_at' => now()])->save();

        self::assertTrue(QueueCycle::query()->sole()->isDrained());
    }

    private function issueTicket(StudentProfile $profile): IssuedFreshmanQueueTicket
    {
        $admission = User::query()->where('email', 'admission@grc.test')->first() ?? User::create([
            'name' => 'Admission', 'email' => 'admission@grc.test', 'password' => self::PASSWORD,
            'role' => UserRole::AdmissionStaff, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return $this->app->make(IssueFreshmanQueueTicket::class)
            ->execute($profile, $admission, new AuditRequestContext('test-issue', null));
    }

    private function cycle(): QueueCycle
    {
        return QueueCycle::query()->whereNull('closed_at')->first()
            ?? QueueCycle::create(['opened_on' => now()->toDateString(), 'last_ticket_sequence' => 1]);
    }

    private function cashierToken(): string
    {
        User::query()->where('email', 'cashier@grc.test')->first() ?? User::create([
            'name' => 'Cashier', 'email' => 'cashier@grc.test', 'password' => self::PASSWORD,
            'role' => UserRole::AccountingStaff, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => 'cashier@grc.test', 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    private function freshman(string $studentNumber, StudentType $type = StudentType::Freshman): StudentProfile
    {
        $program = Program::query()->first() ?? Program::create(['code' => 'BSIT', 'name' => 'BS Information Technology', 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::query()->first() ?? Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSIT Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
        $user = User::create([
            'name' => 'Fresh Student '.$studentNumber, 'email' => 'fresh.'.$studentNumber.'@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Disabled,
        ]);

        return StudentProfile::create([
            'user_id' => $user->id, 'student_number' => $studentNumber,
            'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'year_level' => 1,
            'enrollment_category' => 'regular', 'student_type' => $type,
            'admission_status' => AdmissionStatus::Admitted, 'academic_standing' => AcademicStanding::Good,
        ]);
    }

    private function approvedEnrollment(string $studentNumber): Enrollment
    {
        $term = AcademicTerm::query()->firstOrCreate(
            ['school_year' => '2026-2027', 'semester' => '1st'],
            ['status' => AcademicTermStatus::SemesterOngoing],
        );
        $student = $this->freshman($studentNumber, StudentType::ExistingStudent);

        return Enrollment::create([
            'student_id' => $student->id, 'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::PendingPayment, 'total_units' => 3,
        ]);
    }
}
