<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Mail\FreshmanQueueTicketMail;
use App\Mail\StudentAccountSetupMail;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\QueueTicket;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A freshman pays the Cashier first, so Admission no longer emails the account
 * setup code at intake: it issues a queue number and emails that, plus a link to
 * the live queue. The setup email goes out only after the first payment (that
 * half is covered with the Cashier's payment confirmation).
 */
final class FreshmanAdmissionIntakeTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'https://www.grc-enrollment.tech']);
    }

    public function test_admitting_a_freshman_issues_a_queue_number_and_emails_it_instead_of_the_setup_code(): void
    {
        Mail::fake();
        $token = $this->admissionToken();

        $response = $this->withToken($token)->postJson('/api/v1/student-profiles', $this->payload('juan@grc.test', 'freshman'));

        $response->assertCreated()
            ->assertJsonPath('data.student_type', 'freshman')
            ->assertJsonPath('data.queue_ticket_number', 'Q001')
            ->assertJsonPath('data.queue_link_delivery_status', 'sent')
            ->assertJsonPath('data.account_setup_status', 'awaiting_payment')
            ->assertJsonPath('data.invitation_delivery_status', 'not_sent')
            ->assertJsonPath('data.freshman_payment_confirmed_at', null);

        Mail::assertSent(FreshmanQueueTicketMail::class, fn (FreshmanQueueTicketMail $mail): bool => $mail->hasTo('juan@grc.test') && $mail->ticketNumber === 'Q001');
        Mail::assertNotSent(StudentAccountSetupMail::class);
        Mail::assertSentCount(1);

        $profile = StudentProfile::query()->sole();
        $ticket = QueueTicket::query()->sole();
        self::assertSame($profile->id, $ticket->student_profile_id);
        self::assertNull($ticket->enrollment_id);
        // No setup code exists and the account is still switched off.
        self::assertSame(0, DB::table('account_setup_codes')->count());
        self::assertSame(UserStatus::Disabled, $profile->user->status);
        self::assertSame(0, AuditLog::query()->where('action', AuditAction::STUDENT_ACCOUNT_SETUP_INVITATION_SENT)->count());
    }

    public function test_the_email_carries_a_working_live_queue_link(): void
    {
        Mail::fake();

        $this->withToken($this->admissionToken())->postJson('/api/v1/student-profiles', $this->payload('juan@grc.test', 'freshman'))->assertCreated();

        Mail::assertSent(FreshmanQueueTicketMail::class, function (FreshmanQueueTicketMail $mail): bool {
            $prefix = 'https://www.grc-enrollment.tech/queue/live?t=';

            return str_starts_with($mail->liveUrl, $prefix)
                && QueueTicket::findByPublicToken(substr($mail->liveUrl, strlen($prefix))) !== null;
        });
    }

    public function test_a_failure_while_issuing_the_queue_number_leaves_no_half_created_student_behind(): void
    {
        $this->travelTo(Carbon::parse('2027-08-15 10:00:00', 'Asia/Manila'));
        Mail::fake();
        $token = $this->admissionToken();
        $failing = true;
        Event::listen('eloquent.creating: '.QueueTicket::class, function () use (&$failing): void {
            if ($failing) {
                throw new \RuntimeException('simulated failure while issuing the queue number');
            }
        });

        $this->withToken($token)->postJson('/api/v1/student-profiles', $this->payload('juan@grc.test', 'freshman'))
            ->assertStatus(500);

        // Nothing was kept: no account, no profile, no student number used up, no email. Before this was
        // one transaction, the account stayed and the same email was then refused as "already taken".
        self::assertNull(User::query()->where('email', 'juan@grc.test')->first());
        self::assertSame(0, StudentProfile::query()->count());
        self::assertSame(0, DB::table('student_number_sequences')->where('last_value', '>', 0)->count());
        Mail::assertNothingSent();

        // So the same details can simply be sent again, and this time it works from the first number.
        $failing = false;
        $this->withToken($token)->postJson('/api/v1/student-profiles', $this->payload('juan@grc.test', 'freshman'))
            ->assertCreated()
            ->assertJsonPath('data.student_number', '2027-08-00001')
            ->assertJsonPath('data.queue_ticket_number', 'Q001');
        self::assertSame(1, StudentProfile::query()->count());
    }

    public function test_a_failed_queue_email_does_not_stop_the_intake_and_is_shown_as_failed(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $this->withToken($this->admissionToken())->postJson('/api/v1/student-profiles', $this->payload('juan@grc.test', 'freshman'))
            ->assertCreated()
            ->assertJsonPath('data.queue_ticket_number', 'Q001')
            ->assertJsonPath('data.queue_link_delivery_status', 'failed');

        self::assertSame(1, StudentProfile::query()->count());
    }

    public function test_a_transferee_still_gets_the_account_setup_email_and_no_queue_number(): void
    {
        Mail::fake();

        $this->withToken($this->admissionToken())->postJson('/api/v1/student-profiles', $this->payload('tran@grc.test', 'transferee'))
            ->assertCreated()
            ->assertJsonPath('data.queue_ticket_number', null)
            ->assertJsonPath('data.queue_link_delivery_status', null)
            ->assertJsonPath('data.account_setup_status', 'pending')
            ->assertJsonPath('data.invitation_delivery_status', 'sent');

        Mail::assertSent(StudentAccountSetupMail::class);
        Mail::assertNotSent(FreshmanQueueTicketMail::class);
        self::assertSame(0, QueueTicket::query()->count());
    }

    public function test_two_freshmen_take_consecutive_numbers_in_the_same_line(): void
    {
        Mail::fake();
        $token = $this->admissionToken();

        $this->withToken($token)->postJson('/api/v1/student-profiles', $this->payload('a@grc.test', 'freshman'))
            ->assertJsonPath('data.queue_ticket_number', 'Q001');
        $this->withToken($token)->postJson('/api/v1/student-profiles', $this->payload('b@grc.test', 'freshman'))
            ->assertJsonPath('data.queue_ticket_number', 'Q002');
    }

    public function test_admission_can_send_the_queue_link_again_and_the_old_link_stops_working(): void
    {
        Mail::fake();
        $token = $this->admissionToken();
        $profileId = (int) $this->withToken($token)->postJson('/api/v1/student-profiles', $this->payload('juan@grc.test', 'freshman'))->json('data.id');
        $oldUrl = null;
        Mail::assertSent(FreshmanQueueTicketMail::class, function (FreshmanQueueTicketMail $mail) use (&$oldUrl): bool {
            $oldUrl = $mail->liveUrl;

            return true;
        });

        $this->withToken($token)->postJson("/api/v1/student-profiles/{$profileId}/queue-link-resends")
            ->assertOk()
            ->assertJsonPath('data.queue_link_delivery_status', 'sent');

        $urls = [];
        Mail::assertSent(FreshmanQueueTicketMail::class, function (FreshmanQueueTicketMail $mail) use (&$urls): bool {
            $urls[] = $mail->liveUrl;

            return true;
        });
        self::assertCount(2, array_unique($urls));
        $prefix = 'https://www.grc-enrollment.tech/queue/live?t=';
        self::assertNull(QueueTicket::findByPublicToken(substr((string) $oldUrl, strlen($prefix))));
        self::assertNotNull(QueueTicket::findByPublicToken(substr((string) end($urls), strlen($prefix))));
    }

    public function test_the_queue_link_cannot_be_sent_for_a_student_who_has_no_queue_number(): void
    {
        Mail::fake();
        $token = $this->admissionToken();
        $profileId = (int) $this->withToken($token)->postJson('/api/v1/student-profiles', $this->payload('tran@grc.test', 'transferee'))->json('data.id');

        $this->withToken($token)->postJson("/api/v1/student-profiles/{$profileId}/queue-link-resends")
            ->assertUnprocessable();
    }

    public function test_only_admission_can_send_the_queue_link_again(): void
    {
        Mail::fake();
        $profileId = (int) $this->withToken($this->admissionToken())->postJson('/api/v1/student-profiles', $this->payload('juan@grc.test', 'freshman'))->json('data.id');
        $cashier = $this->tokenFor(UserRole::AccountingStaff, 'cashier@grc.test');
        // The guard caches the first request's user; drop it so the cashier's token is read.
        $this->app['auth']->forgetGuards();

        $this->withToken($cashier)->postJson("/api/v1/student-profiles/{$profileId}/queue-link-resends")->assertForbidden();
    }

    public function test_the_setup_email_cannot_be_sent_to_a_freshman_who_has_not_paid(): void
    {
        Mail::fake();
        $token = $this->admissionToken();
        $profileId = (int) $this->withToken($token)->postJson('/api/v1/student-profiles', $this->payload('juan@grc.test', 'freshman'))->json('data.id');

        $this->withToken($token)->postJson("/api/v1/student-profiles/{$profileId}/account-setup-invitations")
            ->assertUnprocessable();

        Mail::assertNotSent(StudentAccountSetupMail::class);
    }

    public function test_the_public_resend_gives_the_same_generic_answer_but_sends_nothing_to_an_unpaid_freshman(): void
    {
        Mail::fake();
        $this->withToken($this->admissionToken())->postJson('/api/v1/student-profiles', $this->payload('juan@grc.test', 'freshman'))->assertCreated();

        $this->postJson('/api/v1/auth/resend-student-account-setup', ['email' => 'juan@grc.test'])
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');

        Mail::assertNotSent(StudentAccountSetupMail::class);
    }

    public function test_once_the_first_payment_is_confirmed_the_setup_email_can_be_sent(): void
    {
        Mail::fake();
        $token = $this->admissionToken();
        $profileId = (int) $this->withToken($token)->postJson('/api/v1/student-profiles', $this->payload('juan@grc.test', 'freshman'))->json('data.id');
        StudentProfile::query()->whereKey($profileId)->update(['freshman_payment_confirmed_at' => now()]);

        $this->withToken($token)->postJson("/api/v1/student-profiles/{$profileId}/account-setup-invitations")
            ->assertOk()
            ->assertJsonPath('data.account_setup_status', 'pending');

        Mail::assertSent(StudentAccountSetupMail::class);
    }

    public function test_the_student_directory_lists_the_queue_number(): void
    {
        Mail::fake();
        $token = $this->admissionToken();
        $this->withToken($token)->postJson('/api/v1/student-profiles', $this->payload('juan@grc.test', 'freshman'))->assertCreated();

        $this->withToken($token)->getJson('/api/v1/student-profiles')
            ->assertOk()
            ->assertJsonPath('data.0.queue_ticket_number', 'Q001')
            ->assertJsonPath('data.0.account_setup_status', 'awaiting_payment');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $email, string $studentType): array
    {
        $program = Program::query()->first() ?? Program::create(['code' => 'BSIT', 'name' => 'BS Information Technology', 'status' => ProgramStatus::Active]);
        Curriculum::query()->first() ?? Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSIT Curriculum',
            'effective_school_year' => '2026-2027', 'effective_start_year' => 2026,
            'effective_end_year' => 2030, 'status' => CurriculumStatus::Active,
        ]);
        AcademicTerm::query()->first() ?? AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);

        return [
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'email' => $email,
            'gender' => 'F',
            'birth_date' => '2006-05-04',
            'citizenship' => 'Filipino',
            'contact_number' => '09171234567',
            'street_barangay' => '123 Test Street, Caloocan City',
            'town_city' => 'Caloocan City',
            'province' => 'Metro Manila',
            'program_id' => $program->id, 'year_level' => 1,
            'requirements_verified' => true,
            'privacy_notice_acknowledged' => true,
            'privacy_notice_version' => config('privacy.notice_version'),
            'student_type' => $studentType,
        ];
    }

    private function admissionToken(): string
    {
        return $this->tokenFor(UserRole::AdmissionStaff, 'admission@grc.test');
    }

    private function tokenFor(UserRole $role, string $email): string
    {
        User::query()->where('email', $email)->first() ?? User::create([
            'name' => 'Test '.$role->value, 'email' => $email, 'password' => self::PASSWORD,
            'role' => $role, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => self::PASSWORD])->json('data.token');
    }
}
