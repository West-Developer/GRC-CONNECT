<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Identity\StudentType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Mail\StudentAccountSetupMail;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BuildsFreshmen;
use Tests\TestCase;

/**
 * Admission's freshman view (2026-10-16): the student directory can be narrowed to freshmen and to where
 * their account stands, so Admission sees who has paid and who still needs the setup email sent again
 * (a student's mailbox is often full). Same endpoint and permission as the directory; nothing new is exposed.
 */
final class AdmissionFreshmenDirectoryFilterTest extends TestCase
{
    use BuildsFreshmen;
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_the_directory_can_be_narrowed_to_freshmen(): void
    {
        $this->unpaidFreshman('2026-10-00001');
        $this->freshmanProfile('2026-10-00002', StudentType::Transferee);
        $token = $this->admissionToken();

        $this->withToken($token)->getJson('/api/v1/student-profiles')->assertOk()->assertJsonCount(2, 'data');
        $this->withToken($token)->getJson('/api/v1/student-profiles?student_type=freshman')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_number', '2026-10-00001');
    }

    public function test_awaiting_payment_lists_only_freshmen_the_cashier_has_not_confirmed(): void
    {
        $this->unpaidFreshman('2026-10-00001');
        $this->paidFreshman('2026-10-00002');
        // A transferee with no payment yet is not "awaiting payment" here: the account status mirrors the row's badge.
        $this->freshmanProfile('2026-10-00003', StudentType::Transferee);

        $this->withToken($this->admissionToken())->getJson('/api/v1/student-profiles?account_setup_status=awaiting_payment')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_number', '2026-10-00001')
            ->assertJsonPath('data.0.account_setup_status', 'awaiting_payment')
            ->assertJsonPath('data.0.freshman_payment_confirmed_at', null);
    }

    public function test_pending_lists_paid_freshmen_whose_account_is_not_set_up_yet(): void
    {
        $this->unpaidFreshman('2026-10-00001');
        $this->paidFreshman('2026-10-00002');
        $activated = $this->paidFreshman('2026-10-00003');
        $activated->user->forceFill(['account_setup_completed_at' => now(), 'status' => UserStatus::Active])->save();

        $response = $this->withToken($this->admissionToken())
            ->getJson('/api/v1/student-profiles?student_type=freshman&account_setup_status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_number', '2026-10-00002')
            ->assertJsonPath('data.0.account_setup_status', 'pending');
        self::assertNotNull($response->json('data.0.freshman_payment_confirmed_at'));
    }

    public function test_pending_without_the_type_filter_also_lists_everyone_else_still_to_set_up(): void
    {
        $this->unpaidFreshman('2026-10-00001');
        $this->freshmanProfile('2026-10-00002', StudentType::Transferee);
        // An older profile that predates the student type: SQL `NOT (NULL AND ...)` must not hide it.
        $older = $this->freshmanProfile('2026-10-00003');
        StudentProfile::query()->whereKey($older->id)->update(['student_type' => null]);

        $numbers = collect($this->withToken($this->admissionToken())->getJson('/api/v1/student-profiles?account_setup_status=pending')
            ->assertOk()->json('data'))->pluck('student_number')->sort()->values()->all();

        self::assertSame(['2026-10-00002', '2026-10-00003'], $numbers);
    }

    public function test_active_lists_the_accounts_the_student_already_set_up(): void
    {
        $this->paidFreshman('2026-10-00001');
        $activated = $this->paidFreshman('2026-10-00002');
        $activated->user->forceFill(['account_setup_completed_at' => now(), 'status' => UserStatus::Active])->save();

        $this->withToken($this->admissionToken())->getJson('/api/v1/student-profiles?account_setup_status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_number', '2026-10-00002');
    }

    public function test_the_filters_combine_with_the_search_and_pagination(): void
    {
        foreach (range(1, 3) as $n) {
            $this->paidFreshman(sprintf('2026-10-%05d', $n));
        }
        $token = $this->admissionToken();

        $this->withToken($token)->getJson('/api/v1/student-profiles?student_type=freshman&account_setup_status=pending&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3);
        $this->withToken($token)->getJson('/api/v1/student-profiles?student_type=freshman&account_setup_status=pending&search=2026-10-00003')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_number', '2026-10-00003');
    }

    public function test_the_pay_first_intake_leaves_out_historical_records_and_lists_the_newest_first(): void
    {
        // An imported past student typed "freshman": no queue number, no payment, an account that was never set up.
        // Without this filter it would read "Awaiting payment" and bury the freshmen Admission is processing.
        $this->freshmanProfile('HIST-2019-10001');
        $withTicket = $this->unpaidFreshman('2026-10-00001');
        $paid = $this->paidFreshman('2026-10-00002');
        $token = $this->admissionToken();

        $this->withToken($token)->getJson('/api/v1/student-profiles?student_type=freshman')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $response = $this->withToken($token)->getJson('/api/v1/student-profiles?student_type=freshman&intake=pay_first')
            ->assertOk()
            ->assertJsonCount(2, 'data');
        self::assertSame(
            [$paid->student_number, $withTicket->student_number],
            collect($response->json('data'))->pluck('student_number')->all(),
        );
    }

    public function test_the_pay_first_intake_keeps_a_paid_freshman_who_has_no_queue_number(): void
    {
        $this->freshmanProfile('2026-10-00003')->forceFill(['freshman_payment_confirmed_at' => now()])->save();

        $this->withToken($this->admissionToken())->getJson('/api/v1/student-profiles?student_type=freshman&intake=pay_first')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_number', '2026-10-00003');
    }

    public function test_an_unknown_filter_value_is_rejected(): void
    {
        $token = $this->admissionToken();
        $this->withToken($token)->getJson('/api/v1/student-profiles?intake=walk_in')->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->withToken($token)->getJson('/api/v1/student-profiles?student_type=alumnus')->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->withToken($token)->getJson('/api/v1/student-profiles?account_setup_status=lost')->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_the_row_shows_whether_the_setup_email_failed_and_a_resend_after_payment_fixes_it(): void
    {
        Mail::fake();
        $profile = $this->paidFreshman('2026-10-00001');
        $profile->user->forceFill(['account_setup_invitation_failed_at' => now()])->save();
        $token = $this->admissionToken();

        $this->withToken($token)->getJson('/api/v1/student-profiles?student_type=freshman&account_setup_status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.invitation_delivery_status', 'failed');

        $this->withToken($token)->postJson("/api/v1/student-profiles/{$profile->id}/account-setup-invitations")
            ->assertOk()
            ->assertJsonPath('data.invitation_delivery_status', 'sent');
        Mail::assertSent(StudentAccountSetupMail::class, 1);
    }

    public function test_only_admission_can_use_the_directory_filters(): void
    {
        $this->paidFreshman('2026-10-00001');

        foreach ([UserRole::AccountingStaff, UserRole::RegistrarStaff, UserRole::ProgramChair, UserRole::Student] as $role) {
            $token = $this->tokenFor($role, 'other.'.$role->value.'@grc.test');
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->getJson('/api/v1/student-profiles?student_type=freshman&account_setup_status=pending')->assertForbidden();
            $this->app['auth']->forgetGuards();
        }
    }

    private function unpaidFreshman(string $number): StudentProfile
    {
        $profile = $this->freshmanProfile($number);
        $this->issueFreshmanTicket($profile);

        return $profile;
    }

    private function paidFreshman(string $number): StudentProfile
    {
        $profile = $this->unpaidFreshman($number);
        $profile->forceFill(['freshman_payment_confirmed_at' => now()])->save();

        return $profile->fresh(['user']);
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
