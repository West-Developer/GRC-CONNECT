<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Mail\StaffAccountSetupMail;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Auth\AccountSetupCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

final class StaffInvitationsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function tokenFor(UserRole $role, string $email): string
    {
        User::create([
            'name' => 'Test '.$role->value, 'email' => $email,
            'password' => self::PASSWORD, 'role' => $role, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/staff-invitations')->assertUnauthorized();
        $this->postJson('/api/v1/staff-invitations', [])->assertUnauthorized();
    }

    public function test_staff_account_setup_email_includes_the_email_and_code_in_its_link(): void
    {
        $mail = new StaffAccountSetupMail(
            UserRole::Dean,
            'http://localhost:3000/staff-account-setup',
            '123456',
            'invited.dean@grc.test',
        );

        $html = $mail->render();

        self::assertStringContainsString('email=invited.dean%40grc.test', $html);
        self::assertStringContainsString('code=123456', $html);
    }

    public function test_a_non_registrar_head_role_cannot_invite_staff(): void
    {
        $token = $this->tokenFor(UserRole::RegistrarStaff, 'registrar.staff@grc.test');

        $this->withToken($token)->postJson('/api/v1/staff-invitations', [
            'email' => 'new.faculty@grc.test', 'role' => 'faculty',
        ])->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/staff-invitations')->assertForbidden();
    }

    public function test_registrar_head_invites_a_faculty_account(): void
    {
        Mail::fake();
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar.head@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/staff-invitations', [
            'email' => 'new.faculty@grc.test', 'role' => 'faculty',
        ]);

        $response->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonPath('data.email', 'new.faculty@grc.test');
        $response->assertJsonPath('data.role', 'faculty');
        $response->assertJsonPath('data.role_label', 'Professor / Faculty');
        $response->assertJsonPath('data.status', 'pending');
        self::assertSame(
            ['type', 'id', 'email', 'name', 'role', 'role_label', 'status', 'invitation_sent_at', 'activated_at'],
            array_keys($response->json('data')),
        );

        $this->assertDatabaseHas('users', [
            'email' => 'new.faculty@grc.test', 'role' => 'faculty', 'status' => 'disabled',
            'account_setup_completed_at' => null,
        ]);
        Mail::assertSentCount(1);
        Mail::assertSent(StaffAccountSetupMail::class, fn (StaffAccountSetupMail $mail): bool => $mail->role === UserRole::Faculty);
    }

    public function test_registrar_head_can_invite_every_allowed_role(): void
    {
        Mail::fake();
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar.head.roles@grc.test');

        foreach (UserRole::registrarInvitableCases() as $index => $role) {
            $response = $this->withToken($token)->postJson('/api/v1/staff-invitations', [
                'email' => "invitee{$index}@grc.test", 'role' => $role->value,
            ]);

            $response->assertCreated()->assertJsonPath('data.role', $role->value);
        }
    }

    public function test_student_and_admission_staff_roles_are_rejected(): void
    {
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar.head.reject@grc.test');

        foreach (['student', 'admission_staff', 'queue_kiosk', 'not_a_role'] as $role) {
            $this->withToken($token)->postJson('/api/v1/staff-invitations', [
                'email' => 'someone@grc.test', 'role' => $role,
            ])->assertUnprocessable();
        }
        $this->assertDatabaseMissing('users', ['email' => 'someone@grc.test']);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar.head.dup@grc.test');
        User::create(['name' => 'Existing', 'email' => 'existing.staff@grc.test', 'password' => 'irrelevant', 'role' => UserRole::Dean, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);

        $this->withToken($token)->postJson('/api/v1/staff-invitations', [
            'email' => 'existing.staff@grc.test', 'role' => 'dean',
        ])->assertUnprocessable();
    }

    public function test_registrar_head_lists_invitations_across_roles(): void
    {
        Mail::fake();
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar.head.list@grc.test');
        $this->withToken($token)->postJson('/api/v1/staff-invitations', ['email' => 'a.dean@grc.test', 'role' => 'dean'])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/staff-invitations', ['email' => 'an.itadmin@grc.test', 'role' => 'it_admin'])->assertCreated();

        $response = $this->withToken($token)->getJson('/api/v1/staff-invitations');

        $response->assertOk()->assertJsonCount(3, 'data');
        self::assertSame(
            ['a.dean@grc.test', 'an.itadmin@grc.test', 'registrar.head.list@grc.test'],
            collect($response->json('data'))->pluck('email')->sort()->values()->all(),
        );
    }

    public function test_resend_is_rejected_for_a_role_outside_the_invitable_set(): void
    {
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar.head.resend@grc.test');
        $student = User::create(['name' => 'Some Student', 'email' => 'blocked.student@grc.test', 'password' => 'irrelevant', 'role' => UserRole::Student, 'status' => UserStatus::Disabled]);

        $this->withToken($token)->postJson("/api/v1/staff-invitations/{$student->id}/resend")->assertNotFound();
    }

    public function test_resend_sends_another_invitation_for_a_pending_staff_account(): void
    {
        Mail::fake();
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar.head.resend2@grc.test');
        $created = $this->withToken($token)->postJson('/api/v1/staff-invitations', [
            'email' => 'resend.target@grc.test', 'role' => 'accounting_staff',
        ]);
        $userId = $created->json('data.id');

        $response = $this->withToken($token)->postJson("/api/v1/staff-invitations/{$userId}/resend");

        $response->assertOk()->assertJsonPath('data.status', 'pending');
        Mail::assertSentCount(2);
    }

    public function test_mail_failure_keeps_the_pending_account_and_exposes_a_failed_status(): void
    {
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar.head.failure@grc.test');
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('Simulated mail transport failure.'));

        $response = $this->withToken($token)->postJson('/api/v1/staff-invitations', [
            'email' => 'mail.failure.staff@grc.test', 'role' => 'program_chair',
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'failed');
        $this->assertDatabaseHas('users', ['email' => 'mail.failure.staff@grc.test', 'status' => 'disabled']);
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::STAFF_ACCOUNT_SETUP_INVITATION_FAILED)->count());
    }

    public function test_a_staff_member_activates_their_pending_account_with_the_emailed_code_and_own_name(): void
    {
        Mail::fake();
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar.head.activate@grc.test');
        $this->withToken($token)->postJson('/api/v1/staff-invitations', [
            'email' => 'pending.dean@grc.test', 'role' => 'dean',
        ])->assertCreated();

        $setupCode = null;
        Mail::assertSent(StaffAccountSetupMail::class, function (StaffAccountSetupMail $mail) use (&$setupCode): bool {
            $setupCode = $mail->setupCode;

            return $mail->setupUrl === 'http://192.168.1.101:3000/staff-account-setup'
                && $mail->staffEmail === 'pending.dean@grc.test';
        });
        self::assertIsString($setupCode);

        $this->postJson('/api/v1/auth/staff-account-setup', [
            'email' => 'pending.dean@grc.test',
            'code' => $setupCode,
            'name' => 'Aurora Dean Santos',
            'password' => 'New-Secure-Password1!',
            'password_confirmation' => 'New-Secure-Password1!',
        ])->assertOk()
            ->assertJsonPath('data.type', 'staff-account-setup')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('users', [
            'email' => 'pending.dean@grc.test', 'name' => 'Aurora Dean Santos',
            'role' => 'dean', 'status' => 'active',
        ]);
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::STAFF_ACCOUNT_ACTIVATED)->count());

        $this->postJson('/api/v1/auth/login', [
            'email' => 'pending.dean@grc.test', 'password' => 'New-Secure-Password1!',
        ])->assertOk();
    }

    public function test_activation_rejects_an_invalid_code(): void
    {
        Mail::fake();
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar.head.badcode@grc.test');
        $this->withToken($token)->postJson('/api/v1/staff-invitations', [
            'email' => 'bad.code.staff@grc.test', 'role' => 'registrar_staff',
        ])->assertCreated();

        $this->postJson('/api/v1/auth/staff-account-setup', [
            'email' => 'bad.code.staff@grc.test',
            'code' => '123456',
            'name' => 'Someone',
            'password' => 'New-Secure-Password1!',
            'password_confirmation' => 'New-Secure-Password1!',
        ])->assertUnprocessable()
            ->assertJsonPath('error.errors.code.0', 'The setup code is invalid or expired.');
    }

    public function test_activation_rejects_a_student_email_even_with_a_valid_looking_request(): void
    {
        $student = User::create([
            'name' => 'A Student', 'email' => 'a.student@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Disabled,
            'account_setup_completed_at' => null,
        ]);
        $studentCode = app(AccountSetupCodes::class)->issue($student);

        $this->postJson('/api/v1/auth/staff-account-setup', [
            'email' => 'a.student@grc.test',
            'code' => $studentCode,
            'name' => 'Someone',
            'password' => 'New-Secure-Password1!',
            'password_confirmation' => 'New-Secure-Password1!',
        ])->assertUnprocessable()
            ->assertJsonPath('error.errors.code.0', 'The setup code is invalid or expired.');
    }
}
