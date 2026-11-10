<?php

namespace Tests\Feature\Actions\Enrollment;

use App\Actions\Enrollment\IssueFreshmanQueueTicket;
use App\Actions\Enrollment\SendFreshmanQueueTicketEmail;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Enrollment\IssuedFreshmanQueueTicket;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\StudentType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\ProgramStatus;
use App\Mail\FreshmanQueueTicketMail;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

final class SendFreshmanQueueTicketEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'https://www.grc-enrollment.tech']);
    }

    public function test_it_emails_the_queue_number_and_a_live_view_link_to_the_freshman(): void
    {
        Mail::fake();
        [$profile, $issued, $admission] = $this->issued();

        $result = $this->app->make(SendFreshmanQueueTicketEmail::class)
            ->handle($issued->ticket, (string) $issued->plainToken, $admission, new AuditRequestContext('test', null));

        self::assertSame('sent', $result);
        Mail::assertSent(FreshmanQueueTicketMail::class, function (FreshmanQueueTicketMail $mail) use ($profile, $issued): bool {
            return $mail->hasTo($profile->user->email)
                && $mail->ticketNumber === 'Q001'
                && $mail->liveUrl === 'https://www.grc-enrollment.tech/queue/live?t='.$issued->plainToken;
        });
        self::assertNotNull($issued->ticket->fresh()->link_sent_at);
        self::assertNull($issued->ticket->fresh()->link_failed_at);
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::FRESHMAN_QUEUE_LINK_EMAIL_SENT)->count());
    }

    public function test_a_delivery_failure_is_recorded_and_never_thrown(): void
    {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));
        [, $issued, $admission] = $this->issued();

        $result = $this->app->make(SendFreshmanQueueTicketEmail::class)
            ->handle($issued->ticket, (string) $issued->plainToken, $admission, new AuditRequestContext('test', null));

        self::assertSame('failed', $result);
        self::assertNotNull($issued->ticket->fresh()->link_failed_at);
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::FRESHMAN_QUEUE_LINK_EMAIL_SEND_FAILED)->count());
    }

    public function test_the_email_shows_the_number_and_repeats_the_link_as_plain_text(): void
    {
        $mail = new FreshmanQueueTicketMail('Juan Dela Cruz', 'Q014', 'https://www.grc-enrollment.tech/queue/live?t=abc123');
        $html = $mail->render();

        self::assertStringContainsString('Q014', $html);
        self::assertStringContainsString('Cashier', $html);
        // A block button, and the same link as visible text for clients that do not make buttons tappable.
        self::assertMatchesRegularExpression('/<a href="https:\/\/www\.grc-enrollment\.tech\/queue\/live\?t=abc123"[^>]*display:block/', $html);
        self::assertSame(2, substr_count($html, 'href="https://www.grc-enrollment.tech/queue/live?t=abc123"'));
        // It must not promise an account yet.
        self::assertStringNotContainsString('one-time code', $html);
    }

    public function test_production_array_mail_keeps_the_queue_number_and_records_a_delivery_failure(): void
    {
        Mail::fake();
        [, $issued, $admission] = $this->issued();
        $this->app->detectEnvironment(fn (): string => 'production');
        config()->set('mail.default', 'array');

        $result = app(SendFreshmanQueueTicketEmail::class)
            ->handle($issued->ticket, (string) $issued->plainToken, $admission, new AuditRequestContext('test', null));

        self::assertSame('failed', $result);
        self::assertSame('Q001', $issued->ticket->fresh()->ticket_number);
        self::assertNull($issued->ticket->fresh()->link_sent_at);
        self::assertNotNull($issued->ticket->fresh()->link_failed_at);
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::FRESHMAN_QUEUE_LINK_EMAIL_SEND_FAILED)->count());
        Mail::assertNothingSent();
    }

    /**
     * @return array{StudentProfile, IssuedFreshmanQueueTicket, User}
     */
    private function issued(): array
    {
        $program = Program::create(['code' => 'BSIT', 'name' => 'BS Information Technology', 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSIT Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
        $user = User::create([
            'name' => 'Juan Dela Cruz', 'email' => 'juan@grc.test', 'password' => 'correct-horse-battery-staple',
            'role' => UserRole::Student, 'status' => UserStatus::Disabled,
        ]);
        $profile = StudentProfile::create([
            'user_id' => $user->id, 'student_number' => '2026-10-00001',
            'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'year_level' => 1,
            'enrollment_category' => 'regular', 'student_type' => StudentType::Freshman,
            'admission_status' => AdmissionStatus::Admitted, 'academic_standing' => AcademicStanding::Good,
        ]);
        $admission = User::create([
            'name' => 'Admission', 'email' => 'admission@grc.test', 'password' => 'correct-horse-battery-staple',
            'role' => UserRole::AdmissionStaff, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        $issued = $this->app->make(IssueFreshmanQueueTicket::class)
            ->execute($profile, $admission, new AuditRequestContext('test', null));

        return [$profile, $issued, $admission];
    }
}
