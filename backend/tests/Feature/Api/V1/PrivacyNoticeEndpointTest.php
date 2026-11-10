<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Identity\StudentType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\PrivacyNoticeAcceptance;
use App\Models\Program;
use App\Models\User;
use App\Support\Auth\AccountSetupCodes;
use App\Support\Privacy\PrivacyNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Data Privacy Notice (RA 10173, ADR 0055): every signed-in person reads it and agrees before using
 * the portal, Admission records that an applicant was shown it, and the system remembers who agreed to which
 * version so a changed notice is asked again.
 */
final class PrivacyNoticeEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_the_notice_is_public_and_names_the_version_and_a_contact(): void
    {
        config([
            'privacy.notice_version' => '2026-10-18',
            'privacy.effective_date' => '2026-10-18',
            'privacy.contact.name' => null,
            'privacy.contact.email' => 'info@grc.edu.ph',
            'privacy.contact.phone' => null,
        ]);

        $this->getJson('/api/v1/privacy-notice')
            ->assertOk()
            ->assertJsonPath('data.type', 'privacy-notice')
            ->assertJsonPath('data.version', '2026-10-18')
            ->assertJsonPath('data.effective_date', '2026-10-18')
            ->assertJsonPath('data.controller', 'Global Reciprocal Colleges')
            ->assertJsonPath('data.contact.name', null)
            ->assertJsonPath('data.contact.email', 'info@grc.edu.ph')
            ->assertJsonPath('data.contact.phone', null);
    }

    public function test_a_named_data_protection_officer_is_shown_when_one_is_configured(): void
    {
        config([
            'privacy.contact.name' => 'Data Protection Officer',
            'privacy.contact.email' => 'dpo@grc.test',
            'privacy.contact.phone' => '02 8000 0000',
        ]);

        $this->getJson('/api/v1/privacy-notice')
            ->assertOk()
            ->assertJsonPath('data.contact.name', 'Data Protection Officer')
            ->assertJsonPath('data.contact.email', 'dpo@grc.test')
            ->assertJsonPath('data.contact.phone', '02 8000 0000');
    }

    public function test_a_person_who_has_not_agreed_is_told_so_by_the_session_endpoint(): void
    {
        config(['privacy.notice_version' => '2026-10-18']);
        $this->signedIn(UserRole::Student);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.privacy_notice.version', '2026-10-18')
            ->assertJsonPath('data.privacy_notice.accepted_version', null)
            ->assertJsonPath('data.privacy_notice.accepted_at', null)
            ->assertJsonPath('data.privacy_notice.requires_acceptance', true);
    }

    public function test_agreeing_is_recorded_with_the_version_and_the_time_and_audited(): void
    {
        config(['privacy.notice_version' => '2026-10-18']);
        $user = $this->signedIn(UserRole::Student);

        $this->postJson('/api/v1/auth/privacy-notice/accept', ['notice_version' => '2026-10-18'])
            ->assertOk()
            ->assertJsonPath('data.type', 'user')
            ->assertJsonPath('data.privacy_notice.accepted_version', '2026-10-18')
            ->assertJsonPath('data.privacy_notice.requires_acceptance', false);

        $this->assertDatabaseHas('privacy_notice_acceptances', [
            'user_id' => $user->id,
            'notice_version' => '2026-10-18',
            'method' => 'self',
            'recorded_by_user_id' => null,
        ]);
        $this->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.privacy_notice.requires_acceptance', false);

        $audit = AuditLog::query()->where('action', AuditAction::PRIVACY_NOTICE_ACCEPTED)->sole();
        self::assertSame($user->id, $audit->actor_user_id);
        self::assertSame('2026-10-18', $audit->after_values['notice_version']);
    }

    public function test_agreeing_twice_keeps_one_record_and_the_first_time(): void
    {
        config(['privacy.notice_version' => '2026-10-18']);
        $user = $this->signedIn(UserRole::Faculty);

        $this->postJson('/api/v1/auth/privacy-notice/accept', ['notice_version' => '2026-10-18'])->assertOk();
        $firstAt = PrivacyNoticeAcceptance::query()->where('user_id', $user->id)->sole()->accepted_at;

        $this->travel(5)->minutes();
        $this->postJson('/api/v1/auth/privacy-notice/accept', ['notice_version' => '2026-10-18'])->assertOk();

        $row = PrivacyNoticeAcceptance::query()->where('user_id', $user->id)->sole();
        self::assertTrue($row->accepted_at->equalTo($firstAt));
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::PRIVACY_NOTICE_ACCEPTED)->count());
    }

    public function test_agreeing_to_a_version_that_is_not_the_current_one_is_refused(): void
    {
        config(['privacy.notice_version' => '2026-10-18']);
        $this->signedIn(UserRole::Student);

        $this->postJson('/api/v1/auth/privacy-notice/accept', ['notice_version' => '2025-01-01'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.errors.notice_version.0', fn ($m) => is_string($m));

        $this->postJson('/api/v1/auth/privacy-notice/accept', [])->assertUnprocessable();

        $this->assertDatabaseCount('privacy_notice_acceptances', 0);
    }

    public function test_agreeing_needs_a_signed_in_person(): void
    {
        $this->postJson('/api/v1/auth/privacy-notice/accept', ['notice_version' => '2026-10-18'])
            ->assertStatus(401);
    }

    public function test_a_new_version_asks_everyone_again_and_keeps_what_they_agreed_to_before(): void
    {
        config(['privacy.notice_version' => '2026-10-18']);
        $user = $this->signedIn(UserRole::Student);
        $this->postJson('/api/v1/auth/privacy-notice/accept', ['notice_version' => '2026-10-18'])->assertOk();

        config(['privacy.notice_version' => '2027-01-05']);

        $this->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.privacy_notice.version', '2027-01-05')
            ->assertJsonPath('data.privacy_notice.accepted_version', '2026-10-18')
            ->assertJsonPath('data.privacy_notice.requires_acceptance', true);

        $this->postJson('/api/v1/auth/privacy-notice/accept', ['notice_version' => '2027-01-05'])->assertOk();

        self::assertSame(2, PrivacyNoticeAcceptance::query()->where('user_id', $user->id)->count());
        $this->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.privacy_notice.accepted_version', '2027-01-05')
            ->assertJsonPath('data.privacy_notice.requires_acceptance', false);
    }

    public function test_every_role_that_is_a_person_is_asked_but_the_kiosk_device_is_not(): void
    {
        config(['privacy.notice_version' => '2026-10-18']);

        foreach (UserRole::cases() as $role) {
            $user = $this->makeUser($role, $role->value.'.roles@grc.test');
            $payload = UserResource::make($user)->toArray(Request::create('/'));

            self::assertSame(
                $role !== UserRole::QueueKiosk,
                $payload['privacy_notice']['requires_acceptance'],
                $role->value,
            );
        }
    }

    public function test_admission_must_confirm_the_applicant_was_shown_the_notice(): void
    {
        Mail::fake();
        config(['privacy.notice_version' => '2026-10-18']);
        $this->signedIn(UserRole::AdmissionStaff);

        $this->postJson('/api/v1/student-profiles', $this->intake('nonotice@grc.test', withNotice: false))
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.privacy_notice_acknowledged.0', fn ($m) => is_string($m));

        $this->postJson('/api/v1/student-profiles', $this->intake('declined@grc.test', ['privacy_notice_acknowledged' => false]))
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.privacy_notice_acknowledged.0', fn ($m) => is_string($m));

        $this->postJson('/api/v1/student-profiles', $this->intake('oldnotice@grc.test', ['privacy_notice_version' => '2025-01-01']))
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.privacy_notice_version.0', fn ($m) => is_string($m));

        $this->assertDatabaseMissing('users', ['email' => 'nonotice@grc.test']);
        $this->assertDatabaseMissing('users', ['email' => 'declined@grc.test']);
        $this->assertDatabaseMissing('users', ['email' => 'oldnotice@grc.test']);
        $this->assertDatabaseCount('privacy_notice_acceptances', 0);
    }

    public function test_admission_recording_the_notice_is_kept_against_the_new_account_but_the_student_still_agrees_themself(): void
    {
        Mail::fake();
        config(['privacy.notice_version' => '2026-10-18']);
        $admission = $this->signedIn(UserRole::AdmissionStaff);

        $studentId = $this->postJson('/api/v1/student-profiles', $this->intake('shown@grc.test'))
            ->assertCreated()
            ->json('data.id');
        self::assertIsInt($studentId);

        $student = User::query()->where('email', 'shown@grc.test')->firstOrFail();
        $this->assertDatabaseHas('privacy_notice_acceptances', [
            'user_id' => $student->id,
            'notice_version' => '2026-10-18',
            'method' => 'assisted',
            'recorded_by_user_id' => $admission->id,
        ]);

        $audit = AuditLog::query()->where('action', AuditAction::PRIVACY_NOTICE_ACKNOWLEDGED_FOR_STUDENT)->sole();
        self::assertSame($admission->id, $audit->actor_user_id);
        self::assertSame($student->id, $audit->after_values['user_id']);

        // What Admission recorded is evidence the notice was shown; the student's own agreement is still asked.
        $notice = app(PrivacyNotice::class);
        self::assertTrue($notice->requiresAcceptance($student));
        self::assertNull($notice->acceptedVersion($student));
    }

    public function test_an_account_that_was_only_recorded_by_admission_can_still_be_deleted_while_unused(): void
    {
        Mail::fake();
        config(['privacy.notice_version' => '2026-10-18']);
        $this->signedIn(UserRole::AdmissionStaff);
        $this->postJson('/api/v1/student-profiles', $this->intake('unused@grc.test'))->assertCreated();
        $student = User::query()->where('email', 'unused@grc.test')->firstOrFail();

        $this->signedIn(UserRole::ItAdmin);
        $this->deleteJson('/api/v1/it-control/accounts/'.$student->id)->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $student->id]);
        $this->assertDatabaseCount('privacy_notice_acceptances', 0);
    }

    public function test_setting_up_an_account_records_the_agreement_so_the_first_sign_in_does_not_ask_again(): void
    {
        config(['privacy.notice_version' => '2026-10-18']);

        foreach (['student' => UserRole::Student, 'faculty' => UserRole::Faculty, 'staff' => UserRole::RegistrarStaff] as $kind => $role) {
            $user = User::create([
                'name' => 'Pending '.$kind, 'email' => $kind.'.pending@grc.test', 'password' => 'unusable-placeholder',
                'role' => $role, 'status' => UserStatus::Disabled,
            ]);
            $code = app(AccountSetupCodes::class)->issue($user);

            $payload = [
                'email' => $user->email, 'code' => $code,
                'password' => 'New-Secure-Password1!', 'password_confirmation' => 'New-Secure-Password1!',
                'privacy_notice_acknowledged' => true, 'privacy_notice_version' => '2026-10-18',
            ];
            if ($kind !== 'student') {
                $payload['name'] = 'Pending Person';
            }
            $uri = $kind === 'student' ? 'account-setup' : $kind.'-account-setup';

            $this->postJson('/api/v1/auth/'.$uri, $payload)->assertOk();

            $this->assertDatabaseHas('privacy_notice_acceptances', [
                'user_id' => $user->id, 'notice_version' => '2026-10-18', 'method' => 'self',
            ]);
            self::assertFalse(app(PrivacyNotice::class)->requiresAcceptance($user->refresh()), $kind);
        }
    }

    public function test_setting_up_without_the_agreement_still_works_and_the_first_sign_in_asks(): void
    {
        $user = User::create([
            'name' => 'Pending Student', 'email' => 'noagree.pending@grc.test', 'password' => 'unusable-placeholder',
            'role' => UserRole::Student, 'status' => UserStatus::Disabled,
        ]);
        $code = app(AccountSetupCodes::class)->issue($user);

        $this->postJson('/api/v1/auth/account-setup', [
            'email' => $user->email, 'code' => $code,
            'password' => 'New-Secure-Password1!', 'password_confirmation' => 'New-Secure-Password1!',
        ])->assertOk();

        $this->assertDatabaseCount('privacy_notice_acceptances', 0);
        self::assertTrue(app(PrivacyNotice::class)->requiresAcceptance($user->refresh()));
    }

    public function test_setting_up_with_a_stale_notice_version_is_refused_and_the_code_is_kept(): void
    {
        config(['privacy.notice_version' => '2026-10-18']);
        $user = User::create([
            'name' => 'Pending Student', 'email' => 'stale.pending@grc.test', 'password' => 'unusable-placeholder',
            'role' => UserRole::Student, 'status' => UserStatus::Disabled,
        ]);
        $code = app(AccountSetupCodes::class)->issue($user);

        $this->postJson('/api/v1/auth/account-setup', [
            'email' => $user->email, 'code' => $code,
            'password' => 'New-Secure-Password1!', 'password_confirmation' => 'New-Secure-Password1!',
            'privacy_notice_acknowledged' => true, 'privacy_notice_version' => '2025-01-01',
        ])->assertUnprocessable()
            ->assertJsonPath('error.errors.privacy_notice_version.0', fn ($m) => is_string($m));

        self::assertSame(UserStatus::Disabled, $user->refresh()->status);
    }

    private function signedIn(UserRole $role): User
    {
        $user = User::query()->where('email', $role->value.'@grc.test')->first()
            ?? $this->makeUser($role, $role->value.'@grc.test');
        Sanctum::actingAs($user);

        return $user;
    }

    private function makeUser(UserRole $role, string $email): User
    {
        return User::create([
            'name' => 'Test '.$role->value,
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function intake(string $email, array $changes = [], bool $withNotice = true): array
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
            'first_name' => 'Ana', 'last_name' => 'Reyes', 'email' => $email,
            'gender' => 'F', 'birth_date' => '2006-05-04', 'citizenship' => 'Filipino',
            'contact_number' => '09171234567', 'street_barangay' => '123 Test Street, Caloocan City',
            'town_city' => 'Caloocan City', 'province' => 'Metro Manila',
            'program_id' => $program->id, 'year_level' => 1,
            'requirements_verified' => true, 'student_type' => StudentType::Freshman->value,
            ...($withNotice ? ['privacy_notice_acknowledged' => true, 'privacy_notice_version' => '2026-10-18'] : []),
            ...$changes,
        ];
    }
}
