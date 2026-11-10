<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Mail\StudentAccountSetupMail;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A newly created Transferee or Returnee has 7 days from Admission's creation of the account to get
 * their enrollment approved by the Registrar (owner request, 2026-10-14). The date lives on the student
 * profile; accounts that existed before it stay without one and are never restricted or deactivated.
 */
final class EnrollmentDeadlineTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function admissionToken(): string
    {
        User::create([
            'name' => 'Admission',
            'email' => 'admission.deadline@grc.test',
            'password' => self::PASSWORD,
            'role' => UserRole::AdmissionStaff,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => 'admission.deadline@grc.test',
            'password' => self::PASSWORD,
        ])->json('data.token');
    }

    private function program(): Program
    {
        $program = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);
        Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'effective_start_year' => 2026,
            'effective_end_year' => 2030, 'status' => CurriculumStatus::Active,
        ]);
        AcademicTerm::create(['school_year' => '2027-2028', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);

        return $program;
    }

    /** @return array<string, mixed> */
    private function payload(Program $program, string $email, string $studentType): array
    {
        return [
            'student_type' => $studentType,
            'first_name' => 'Deadline',
            'last_name' => 'Student',
            'email' => $email,
            'gender' => 'F',
            'birth_date' => '2006-05-04',
            'citizenship' => 'Filipino',
            'contact_number' => '09171234567',
            'street_barangay' => '123 Test Street, Caloocan City',
            'town_city' => 'Caloocan City',
            'province' => 'Metro Manila',
            'program_id' => $program->id,
            'year_level' => 1,
            'requirements_verified' => true,
            'privacy_notice_acknowledged' => true,
            'privacy_notice_version' => config('privacy.notice_version'),
        ];
    }

    public function test_a_new_transferee_or_returnee_gets_seven_days_from_the_moment_admission_creates_the_account(): void
    {
        $this->travelTo(Carbon::parse('2027-08-15 10:00:00', 'UTC'));
        $program = $this->program();
        $token = $this->admissionToken();
        Mail::fake();

        foreach (['transferee', 'returnee'] as $type) {
            $response = $this->withToken($token)->postJson('/api/v1/student-profiles', $this->payload($program, "{$type}@grc.test", $type));

            $response->assertCreated()
                ->assertJsonPath('data.enrollment_deadline_at', '2027-08-22T10:00:00Z');
        }
    }

    public function test_other_student_types_get_no_deadline(): void
    {
        $program = $this->program();
        $token = $this->admissionToken();
        Mail::fake();

        $response = $this->withToken($token)->postJson('/api/v1/student-profiles', $this->payload($program, 'existing@grc.test', 'existing_student'));

        $response->assertCreated();
        // `assertJsonPath(..., null)` also passes for a missing key, so check the key is really there.
        self::assertArrayHasKey('enrollment_deadline_at', $response->json('data'));
        self::assertNull($response->json('data.enrollment_deadline_at'));
    }

    public function test_a_student_whose_deadline_has_passed_cannot_finish_the_account_setup(): void
    {
        $this->travelTo(Carbon::parse('2027-08-15 10:00:00', 'UTC'));
        $program = $this->program();
        $token = $this->admissionToken();
        Mail::fake();
        $this->withToken($token)->postJson('/api/v1/student-profiles', $this->payload($program, 'late@grc.test', 'transferee'))->assertCreated();
        $setupCode = null;
        Mail::assertSent(StudentAccountSetupMail::class, function (StudentAccountSetupMail $mail) use (&$setupCode): bool {
            $setupCode = $mail->setupCode;

            return true;
        });

        $this->travelTo(Carbon::parse('2027-08-22 10:00:01', 'UTC'));

        $this->postJson('/api/v1/auth/account-setup', [
            'email' => 'late@grc.test',
            'code' => $setupCode,
            'password' => 'New-Secure-Password1!',
            'password_confirmation' => 'New-Secure-Password1!',
        ])->assertUnprocessable()
            ->assertJsonPath('error.errors.code.0', 'The 7-day enrollment window for this account has ended. Please visit Admission to be admitted again.');

        $this->assertDatabaseHas('users', ['email' => 'late@grc.test', 'status' => 'disabled', 'account_setup_completed_at' => null]);
    }

    public function test_resending_the_setup_invitation_after_the_deadline_gives_a_fresh_seven_days_and_the_student_can_activate(): void
    {
        $this->travelTo(Carbon::parse('2027-08-15 10:00:00', 'UTC'));
        $program = $this->program();
        $token = $this->admissionToken();
        Mail::fake();
        $profileId = (int) $this->withToken($token)->postJson('/api/v1/student-profiles', $this->payload($program, 'again@grc.test', 'returnee'))
            ->assertCreated()->json('data.id');

        $this->travelTo(Carbon::parse('2027-08-30 09:00:00', 'UTC'));
        Mail::fake();
        $this->withToken($token)->postJson("/api/v1/student-profiles/{$profileId}/account-setup-invitations")
            ->assertOk()
            ->assertJsonPath('data.enrollment_deadline_at', '2027-09-06T09:00:00Z');

        $setupCode = null;
        Mail::assertSent(StudentAccountSetupMail::class, function (StudentAccountSetupMail $mail) use (&$setupCode): bool {
            $setupCode = $mail->setupCode;

            return true;
        });
        $this->postJson('/api/v1/auth/account-setup', [
            'email' => 'again@grc.test',
            'code' => $setupCode,
            'password' => 'New-Secure-Password1!',
            'password_confirmation' => 'New-Secure-Password1!',
        ])->assertOk();
        $this->assertDatabaseHas('users', ['email' => 'again@grc.test', 'status' => 'active']);
    }

    public function test_resending_the_invitation_before_the_deadline_does_not_extend_it(): void
    {
        $this->travelTo(Carbon::parse('2027-08-15 10:00:00', 'UTC'));
        $program = $this->program();
        $token = $this->admissionToken();
        Mail::fake();
        $profileId = (int) $this->withToken($token)->postJson('/api/v1/student-profiles', $this->payload($program, 'early@grc.test', 'transferee'))
            ->assertCreated()->json('data.id');

        $this->travelTo(Carbon::parse('2027-08-18 10:00:00', 'UTC'));
        $this->withToken($token)->postJson("/api/v1/student-profiles/{$profileId}/account-setup-invitations")
            ->assertOk()
            ->assertJsonPath('data.enrollment_deadline_at', '2027-08-22T10:00:00Z');
    }

    public function test_an_account_from_before_the_deadline_existed_is_never_given_one_and_can_still_be_set_up(): void
    {
        $this->travelTo(Carbon::parse('2027-08-15 10:00:00', 'UTC'));
        $program = $this->program();
        $token = $this->admissionToken();
        Mail::fake();
        $profileId = (int) $this->withToken($token)->postJson('/api/v1/student-profiles', $this->payload($program, 'legacy@grc.test', 'transferee'))
            ->assertCreated()->json('data.id');
        // A row created before the feature shipped has no deadline.
        StudentProfile::query()->whereKey($profileId)->update(['enrollment_deadline_at' => null]);

        $this->travelTo(Carbon::parse('2027-09-30 10:00:00', 'UTC'));
        Mail::fake();
        $this->withToken($token)->postJson("/api/v1/student-profiles/{$profileId}/account-setup-invitations")
            ->assertOk()
            ->assertJsonPath('data.enrollment_deadline_at', null);
        self::assertNull(StudentProfile::query()->findOrFail($profileId)->enrollment_deadline_at);

        $setupCode = null;
        Mail::assertSent(StudentAccountSetupMail::class, function (StudentAccountSetupMail $mail) use (&$setupCode): bool {
            $setupCode = $mail->setupCode;

            return true;
        });
        $this->postJson('/api/v1/auth/account-setup', [
            'email' => 'legacy@grc.test',
            'code' => $setupCode,
            'password' => 'New-Secure-Password1!',
            'password_confirmation' => 'New-Secure-Password1!',
        ])->assertOk();
        self::assertSame(0, AuditLog::query()->where('action', AuditAction::USER_ACCOUNT_DEACTIVATED)->count());
    }
}
