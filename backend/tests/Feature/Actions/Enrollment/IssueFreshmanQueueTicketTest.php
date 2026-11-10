<?php

namespace Tests\Feature\Actions\Enrollment;

use App\Actions\Enrollment\ClaimQueueTicket;
use App\Actions\Enrollment\IssueFreshmanQueueTicket;
use App\Actions\Enrollment\RotateFreshmanQueueToken;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\IssuedFreshmanQueueTicket;
use App\Domain\Enrollment\QueueTicketStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\StudentType;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class IssueFreshmanQueueTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_issues_a_waiting_ticket_for_a_freshman_who_has_no_enrollment(): void
    {
        $profile = $this->freshman('2026-10-00001');

        $issued = $this->issue($profile);

        $ticket = $issued->ticket;
        self::assertSame('Q001', $ticket->ticket_number);
        self::assertSame(QueueTicketStatus::Waiting, $ticket->status);
        self::assertNull($ticket->enrollment_id);
        self::assertSame($profile->id, $ticket->student_profile_id);
        self::assertNotNull($issued->plainToken);
    }

    public function test_only_the_hash_of_the_link_token_is_stored(): void
    {
        $issued = $this->issue($this->freshman('2026-10-00001'));

        $stored = $issued->ticket->fresh()->public_token_hash;

        self::assertSame(hash('sha256', (string) $issued->plainToken), $stored);
        self::assertNotSame($issued->plainToken, $stored);
    }

    public function test_the_token_finds_its_ticket_and_a_wrong_token_finds_nothing(): void
    {
        $issued = $this->issue($this->freshman('2026-10-00001'));

        self::assertSame($issued->ticket->id, QueueTicket::findByPublicToken((string) $issued->plainToken)?->id);
        self::assertNull(QueueTicket::findByPublicToken('not-the-token'));
        self::assertNull(QueueTicket::findByPublicToken(''));
    }

    public function test_issuing_again_returns_the_same_ticket_and_no_new_token(): void
    {
        $profile = $this->freshman('2026-10-00001');

        $first = $this->issue($profile);
        $second = $this->issue($profile);

        self::assertSame($first->ticket->id, $second->ticket->id);
        self::assertNull($second->plainToken);
        self::assertSame(1, QueueTicket::query()->count());
    }

    public function test_a_freshman_waits_in_the_same_line_as_students_who_claimed_at_the_kiosk(): void
    {
        [$enrollment] = $this->approvedEnrollment('2026-08-00002');
        $this->app->make(ClaimQueueTicket::class)
            ->execute($enrollment, $this->cashier(), new AuditRequestContext('test', null));

        $issued = $this->issue($this->freshman('2026-10-00001'));

        self::assertSame('Q002', $issued->ticket->ticket_number);
        self::assertSame(1, QueueCycle::query()->count());
    }

    public function test_it_writes_one_audit_row_naming_the_profile(): void
    {
        $profile = $this->freshman('2026-10-00001');

        $this->issue($profile);

        $row = AuditLog::query()->where('action', AuditAction::QUEUE_TICKET_CLAIMED)->sole();
        self::assertSame($profile->id, $row->after_values['student_profile_id']);
        self::assertSame('freshman_admission', $row->after_values['source']);
        self::assertArrayNotHasKey('public_token_hash', $row->after_values);
    }

    public function test_it_refuses_a_student_who_does_not_pay_first(): void
    {
        // A Returnee follows the ordinary flow; a Transferee now pays first like a freshman (ADR 0043, amended 2026-10-05).
        $profile = $this->freshman('2026-10-00001', StudentType::Returnee);

        $this->expectException(ValidationException::class);

        $this->issue($profile);
    }

    public function test_it_refuses_a_freshman_whose_payment_is_already_confirmed(): void
    {
        $profile = $this->freshman('2026-10-00001');
        $profile->forceFill(['freshman_payment_confirmed_at' => now()])->save();

        $this->expectException(ValidationException::class);

        $this->issue($profile);
    }

    public function test_rotating_the_token_makes_the_old_link_stop_working(): void
    {
        $issued = $this->issue($this->freshman('2026-10-00001'));
        $oldToken = (string) $issued->plainToken;

        $newToken = $this->app->make(RotateFreshmanQueueToken::class)->execute($issued->ticket);

        self::assertNotSame($oldToken, $newToken);
        self::assertNull(QueueTicket::findByPublicToken($oldToken));
        self::assertSame($issued->ticket->id, QueueTicket::findByPublicToken($newToken)?->id);
    }

    public function test_the_token_cannot_be_rotated_once_the_payment_is_confirmed(): void
    {
        $profile = $this->freshman('2026-10-00001');
        $issued = $this->issue($profile);
        $profile->forceFill(['freshman_payment_confirmed_at' => now()])->save();

        $this->expectException(ValidationException::class);

        $this->app->make(RotateFreshmanQueueToken::class)->execute($issued->ticket);
    }

    private function issue(StudentProfile $profile): IssuedFreshmanQueueTicket
    {
        return $this->app->make(IssueFreshmanQueueTicket::class)
            ->execute($profile, $this->admissionStaff(), new AuditRequestContext('test-issue', null));
    }

    private function admissionStaff(): User
    {
        return User::query()->where('email', 'admission@grc.test')->first() ?? User::create([
            'name' => 'Admission', 'email' => 'admission@grc.test', 'password' => 'correct-horse-battery-staple',
            'role' => UserRole::AdmissionStaff, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
    }

    private function cashier(): User
    {
        return User::query()->where('email', 'cashier@grc.test')->first() ?? User::create([
            'name' => 'Cashier', 'email' => 'cashier@grc.test', 'password' => 'correct-horse-battery-staple',
            'role' => UserRole::AccountingStaff, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
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
            'password' => 'correct-horse-battery-staple', 'role' => UserRole::Student, 'status' => UserStatus::Disabled,
        ]);

        return StudentProfile::create([
            'user_id' => $user->id, 'student_number' => $studentNumber,
            'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'year_level' => 1,
            'enrollment_category' => 'regular', 'student_type' => $type,
            'admission_status' => AdmissionStatus::Admitted, 'academic_standing' => AcademicStanding::Good,
        ]);
    }

    /**
     * @return array{Enrollment}
     */
    private function approvedEnrollment(string $studentNumber): array
    {
        $term = AcademicTerm::query()->firstOrCreate(
            ['school_year' => '2026-2027', 'semester' => '1st'],
            ['status' => AcademicTermStatus::SemesterOngoing],
        );
        $student = $this->freshman($studentNumber, StudentType::ExistingStudent);

        return [Enrollment::create([
            'student_id' => $student->id, 'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::PendingPayment, 'total_units' => 3,
        ])];
    }
}
