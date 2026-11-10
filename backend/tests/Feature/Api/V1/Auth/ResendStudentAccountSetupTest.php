<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Actions\Identity\SendStudentAccountSetupInvitation;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Enrollment\EnrollmentCategory;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\ProgramStatus;
use App\Http\Resources\Api\V1\StudentProfileResource;
use App\Mail\StudentAccountSetupMail;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\QueueTicket;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class ResendStudentAccountSetupTest extends TestCase
{
    use RefreshDatabase;

    private function createPendingStudent(string $email = 'pending.resend@grc.test'): StudentProfile
    {
        $program = Program::create([
            'code' => 'BSCS_RESEND',
            'name' => 'BS Computer Science Resend',
            'status' => ProgramStatus::Active,
        ]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id,
            'name' => 'BSCS Resend Curriculum',
            'effective_school_year' => '2026-2027',
            'effective_start_year' => 2026,
            'effective_end_year' => 2030,
            'status' => CurriculumStatus::Active,
        ]);

        $user = User::create([
            'name' => 'Pending Resend Student',
            'first_name' => 'Pending',
            'last_name' => 'Student',
            'email' => $email,
            'role' => UserRole::Student,
            'status' => UserStatus::Disabled,
            'account_setup_completed_at' => null,
            'password' => 'secret-temp-pass',
        ]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => '2026-09-99001',
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'entry_year' => 2026,
            'year_level' => 1,
            'enrollment_category' => EnrollmentCategory::Regular->value,
            'student_type' => 'transferee',
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
            'financial_status' => null,
            'address' => '123 Caloocan St',
            'requirements_verified_at' => now(),
            'requirements_verified_by' => $user->id,
        ]);
    }

    public function test_pending_student_can_request_resend_setup_email(): void
    {
        Mail::fake();
        $profile = $this->createPendingStudent('pending.resend@grc.test');

        $response = $this->postJson('/api/v1/auth/resend-student-account-setup', [
            'email' => 'pending.resend@grc.test',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.type', 'resend-student-account-setup')
            ->assertJsonPath('data.status', 'sent');

        Mail::assertSent(StudentAccountSetupMail::class, function (StudentAccountSetupMail $mail): bool {
            return $mail->studentEmail === 'pending.resend@grc.test';
        });
    }

    public function test_unknown_email_returns_safe_generic_response_without_sending_email(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/auth/resend-student-account-setup', [
            'email' => 'unknown@grc.test',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.type', 'resend-student-account-setup')
            ->assertJsonPath('data.status', 'sent');

        Mail::assertNothingSent();
    }

    public function test_active_student_returns_safe_generic_response_without_sending_email(): void
    {
        Mail::fake();
        $profile = $this->createPendingStudent('active.resend@grc.test');
        $profile->user->forceFill([
            'status' => UserStatus::Active,
            'account_setup_completed_at' => now(),
        ])->save();

        $response = $this->postJson('/api/v1/auth/resend-student-account-setup', [
            'email' => 'active.resend@grc.test',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.type', 'resend-student-account-setup')
            ->assertJsonPath('data.status', 'sent');

        Mail::assertNothingSent();
    }

    public function test_invalid_email_format_fails_validation(): void
    {
        $response = $this->postJson('/api/v1/auth/resend-student-account-setup', [
            'email' => 'not-an-email',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.errors.email.0', fn ($msg) => is_string($msg));
    }

    public function test_production_array_mail_is_recorded_as_failed_without_losing_the_pending_account(): void
    {
        Mail::fake();
        $profile = $this->createPendingStudent();
        $this->app->detectEnvironment(fn (): string => 'production');
        config()->set('mail.default', 'array');

        $result = app(SendStudentAccountSetupInvitation::class)
            ->handle($profile, $profile->user, new AuditRequestContext('test', null));

        self::assertSame('failed', $result);
        self::assertNull($profile->user->fresh()->account_setup_invitation_sent_at);
        self::assertNotNull($profile->user->fresh()->account_setup_invitation_failed_at);
        $this->assertDatabaseHas('users', ['id' => $profile->user_id, 'status' => 'disabled']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::STUDENT_ACCOUNT_SETUP_INVITATION_FAILED,
            'auditable_id' => $profile->id,
        ]);
        Mail::assertNothingSent();
    }

    public function test_a_failed_resend_in_the_same_second_as_a_previous_send_is_shown_as_failed(): void
    {
        $this->freezeTime();
        $profile = $this->createPendingStudent()->load(['user', 'program', 'curriculum']);
        $profile->user->forceFill([
            'account_setup_invitation_sent_at' => now(),
            'account_setup_invitation_failed_at' => now(),
        ]);
        $profile->setRelation('queueTicket', new QueueTicket([
            'ticket_number' => 'Q001',
            'link_sent_at' => now(),
            'link_failed_at' => now(),
        ]));

        $data = StudentProfileResource::make($profile)->resolve(request());

        self::assertSame('failed', $data['invitation_delivery_status']);
        self::assertSame('failed', $data['queue_link_delivery_status']);
    }
}
