<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CollegeCode;
use App\Mail\FacultyAccountSetupMail;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Auth\AccountSetupCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

final class FacultyInvitationsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function programChairToken(string $email, CollegeCode $college): string
    {
        User::create([
            'name' => 'Test Program Chair',
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => UserRole::ProgramChair,
            'college' => $college,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->json('data.token');
    }

    private function tokenFor(UserRole $role, string $email): string
    {
        User::create([
            'name' => 'Test '.$role->value,
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->json('data.token');
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/faculty-invitations')->assertUnauthorized();
        $this->postJson('/api/v1/faculty-invitations', ['email' => 'x@grc.test'])->assertUnauthorized();
    }

    public function test_a_non_program_chair_role_cannot_invite_a_professor(): void
    {
        $token = $this->tokenFor(UserRole::Dean, 'dean.blocked@grc.test');

        $this->withToken($token)->postJson('/api/v1/faculty-invitations', [
            'email' => 'blocked.professor@grc.test',
        ])->assertForbidden();
    }

    public function test_program_chair_can_invite_a_professor_and_it_appears_pending_in_their_college_list(): void
    {
        config(['app.frontend_url' => 'http://localhost:3000']);
        $token = $this->programChairToken('chair.ccs.invite@grc.test', CollegeCode::Ccs);
        Mail::fake();

        $response = $this->withToken($token)->postJson('/api/v1/faculty-invitations', [
            'email' => 'new.professor@grc.test',
        ]);

        $response->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonPath('data.type', 'faculty_invitation');
        $response->assertJsonPath('data.email', 'new.professor@grc.test');
        $response->assertJsonPath('data.status', 'pending');
        $response->assertJsonMissingPath('data.password');

        $this->assertDatabaseHas('users', [
            'email' => 'new.professor@grc.test',
            'role' => 'faculty',
            'college' => 'ccs',
            'status' => 'disabled',
            'account_setup_completed_at' => null,
        ]);
        Mail::assertSentCount(1);
        Mail::assertSent(FacultyAccountSetupMail::class, fn (FacultyAccountSetupMail $mail): bool => $mail->setupUrl === 'http://localhost:3000/faculty-account-setup'
            && ! str_contains($mail->setupUrl, 'token='));
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::FACULTY_ACCOUNT_SETUP_INVITATION_SENT)->count());

        $list = $this->withToken($token)->getJson('/api/v1/faculty-invitations');
        $list->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $list->assertJsonPath('data.0.email', 'new.professor@grc.test');
        $list->assertJsonPath('data.0.status', 'pending');
    }

    public function test_inviting_an_already_registered_email_is_rejected(): void
    {
        $token = $this->programChairToken('chair.ccs.dup@grc.test', CollegeCode::Ccs);
        Mail::fake();

        $this->withToken($token)->postJson('/api/v1/faculty-invitations', [
            'email' => 'chair.ccs.dup@grc.test',
        ])->assertUnprocessable()
            ->assertJsonPath('error.errors.email.0', 'The email has already been taken.');

        Mail::assertNothingSent();
    }

    public function test_the_invitation_list_is_scoped_to_the_chairs_own_college(): void
    {
        // Deliberately precreated directly (not via the API as a second
        // authenticated actor) — chaining two different users' withToken()
        // calls in one test method leaks the first actor into the second
        // request, per the documented Sanctum multi-user testing gotcha.
        User::create([
            'name' => 'Existing COE Professor',
            'email' => 'coe.professor@grc.test',
            'password' => 'unusable-placeholder',
            'role' => UserRole::Faculty,
            'college' => CollegeCode::Coe,
            'status' => UserStatus::Disabled,
        ]);

        $ccsToken = $this->programChairToken('chair.ccs.scope@grc.test', CollegeCode::Ccs);
        Mail::fake();

        $this->withToken($ccsToken)->postJson('/api/v1/faculty-invitations', [
            'email' => 'ccs.professor@grc.test',
        ])->assertCreated();

        $ccsList = $this->withToken($ccsToken)->getJson('/api/v1/faculty-invitations')->json('data');
        self::assertCount(1, $ccsList);
        self::assertSame('ccs.professor@grc.test', $ccsList[0]['email']);
    }

    public function test_professor_can_activate_their_account_with_the_emailed_code_and_set_their_own_name(): void
    {
        $token = $this->programChairToken('chair.ccs.activate@grc.test', CollegeCode::Ccs);
        Mail::fake();

        $this->withToken($token)->postJson('/api/v1/faculty-invitations', [
            'email' => 'pending.professor@grc.test',
        ])->assertCreated();

        $setupCode = null;
        Mail::assertSent(FacultyAccountSetupMail::class, function (FacultyAccountSetupMail $mail) use (&$setupCode): bool {
            $setupCode = $mail->setupCode;

            return true;
        });
        self::assertIsString($setupCode);
        self::assertNotSame('', $setupCode);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'pending.professor@grc.test',
            'password' => 'new-secure-password',
        ])->assertUnauthorized();

        $this->postJson('/api/v1/auth/faculty-account-setup', [
            'email' => 'pending.professor@grc.test',
            'code' => $setupCode,
            'name' => 'Prof. Juan Dela Cruz',
            'password' => 'New-Secure-Password1!',
            'password_confirmation' => 'New-Secure-Password1!',
        ])->assertOk()
            ->assertJsonPath('data.type', 'faculty-account-setup')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonMissingPath('data.token');

        $this->assertDatabaseHas('users', [
            'email' => 'pending.professor@grc.test',
            'name' => 'Prof. Juan Dela Cruz',
            'role' => 'faculty',
            'status' => 'active',
        ]);
        self::assertNotNull(User::query()->where('email', 'pending.professor@grc.test')->value('account_setup_completed_at'));
        $this->assertDatabaseCount('account_setup_codes', 0);
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::FACULTY_ACCOUNT_ACTIVATED)->count());

        $this->postJson('/api/v1/auth/login', [
            'email' => 'pending.professor@grc.test',
            'password' => 'New-Secure-Password1!',
        ])->assertOk();

        $this->postJson('/api/v1/auth/faculty-account-setup', [
            'email' => 'pending.professor@grc.test',
            'code' => $setupCode,
            'name' => 'Someone Else',
            'password' => 'Another-Secure-Password1!',
            'password_confirmation' => 'Another-Secure-Password1!',
        ])->assertUnprocessable();
    }

    public function test_an_expired_or_invalid_code_cannot_activate_a_faculty_account(): void
    {
        $token = $this->programChairToken('chair.ccs.expiry@grc.test', CollegeCode::Ccs);
        Mail::fake();

        $this->withToken($token)->postJson('/api/v1/faculty-invitations', [
            'email' => 'expiring.professor@grc.test',
        ])->assertCreated();

        $setupCode = null;
        Mail::assertSent(FacultyAccountSetupMail::class, function (FacultyAccountSetupMail $mail) use (&$setupCode): bool {
            $setupCode = $mail->setupCode;

            return true;
        });
        DB::table('account_setup_codes')
            ->whereIn('user_id', User::query()->where('email', 'expiring.professor@grc.test')->pluck('id'))
            ->update(['expires_at' => now()->subMinute()]);

        foreach ([$setupCode, '123456'] as $code) {
            $this->postJson('/api/v1/auth/faculty-account-setup', [
                'email' => 'expiring.professor@grc.test',
                'code' => $code,
                'name' => 'Prof. Late',
                'password' => 'New-Secure-Password1!',
                'password_confirmation' => 'New-Secure-Password1!',
            ])->assertUnprocessable()
                ->assertJsonPath('error.errors.code.0', 'The setup code is invalid or expired.');
        }

        $this->assertDatabaseHas('users', [
            'email' => 'expiring.professor@grc.test',
            'status' => 'disabled',
            'account_setup_completed_at' => null,
        ]);
    }

    public function test_a_students_setup_code_cannot_activate_a_faculty_account_and_the_reverse_is_also_true(): void
    {
        $chairToken = $this->programChairToken('chair.ccs.crossrole@grc.test', CollegeCode::Ccs);
        Mail::fake();

        $this->withToken($chairToken)->postJson('/api/v1/faculty-invitations', [
            'email' => 'crossrole.professor@grc.test',
        ])->assertCreated();
        $facultyCode = null;
        Mail::assertSent(FacultyAccountSetupMail::class, function (FacultyAccountSetupMail $mail) use (&$facultyCode): bool {
            $facultyCode = $mail->setupCode;

            return true;
        });

        $student = User::create([
            'name' => 'Pending Student',
            'email' => 'crossrole.student@grc.test',
            'password' => 'unusable-placeholder',
            'role' => UserRole::Student,
            'status' => UserStatus::Disabled,
        ]);
        $studentCode = app(AccountSetupCodes::class)->issue($student);

        // The faculty code must not activate a student account.
        $this->postJson('/api/v1/auth/account-setup', [
            'email' => 'crossrole.professor@grc.test',
            'code' => $facultyCode,
            'password' => 'New-Secure-Password1!',
            'password_confirmation' => 'New-Secure-Password1!',
        ])->assertUnprocessable()
            ->assertJsonPath('error.errors.code.0', 'The setup code is invalid or expired.');

        // The student code must not activate a faculty account.
        $this->postJson('/api/v1/auth/faculty-account-setup', [
            'email' => 'crossrole.student@grc.test',
            'code' => $studentCode,
            'name' => 'Should Not Apply',
            'password' => 'New-Secure-Password1!',
            'password_confirmation' => 'New-Secure-Password1!',
        ])->assertUnprocessable()
            ->assertJsonPath('error.errors.code.0', 'The setup code is invalid or expired.');

        $this->assertDatabaseHas('users', ['email' => 'crossrole.professor@grc.test', 'status' => 'disabled']);
        $this->assertDatabaseHas('users', ['email' => 'crossrole.student@grc.test', 'status' => 'disabled']);
    }

    public function test_resending_a_pending_invitation_sends_a_new_code(): void
    {
        $token = $this->programChairToken('chair.ccs.resend@grc.test', CollegeCode::Ccs);
        Mail::fake();

        $this->withToken($token)->postJson('/api/v1/faculty-invitations', [
            'email' => 'resend.professor@grc.test',
        ])->assertCreated();
        $facultyId = User::query()->where('email', 'resend.professor@grc.test')->value('id');

        $this->withToken($token)->postJson("/api/v1/faculty-invitations/{$facultyId}/resend")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        Mail::assertSentCount(2);
    }

    public function test_resend_is_rejected_for_a_faculty_account_in_another_college(): void
    {
        // Precreated directly for the same reason as the scoping test above
        // — one authenticated actor per test method.
        $coeFaculty = User::create([
            'name' => 'COE Only Professor',
            'email' => 'coe.only.professor@grc.test',
            'password' => 'unusable-placeholder',
            'role' => UserRole::Faculty,
            'college' => CollegeCode::Coe,
            'status' => UserStatus::Disabled,
        ]);

        $ccsToken = $this->programChairToken('chair.ccs.resend-scope@grc.test', CollegeCode::Ccs);
        Mail::fake();

        $this->withToken($ccsToken)->postJson("/api/v1/faculty-invitations/{$coeFaculty->id}/resend")
            ->assertNotFound();
    }

    public function test_mail_failure_on_invite_still_creates_the_account_and_exposes_a_resendable_state(): void
    {
        $token = $this->programChairToken('chair.ccs.mailfail@grc.test', CollegeCode::Ccs);
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('Simulated mail transport failure.'));

        $response = $this->withToken($token)->postJson('/api/v1/faculty-invitations', [
            'email' => 'mailfail.professor@grc.test',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'failed');
        $this->assertDatabaseHas('users', [
            'email' => 'mailfail.professor@grc.test',
            'status' => 'disabled',
        ]);
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::FACULTY_ACCOUNT_SETUP_INVITATION_FAILED)->count());
    }
}
