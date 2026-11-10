<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Identity\StudentType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Mail\EnrollmentConfirmedMail;
use App\Mail\StudentAccountSetupMail;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\AccountPayment;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BuildsBlockSections;
use Tests\Support\BuildsFreshmen;
use Tests\TestCase;

/**
 * `POST /student-profiles/{id}/freshman-payment` is the Cashier's first-payment
 * confirmation for a freshman (ADR 0043); `GET .../freshman-payment-preview`
 * shows what it will do before the Cashier presses the button.
 */
final class FreshmanPaymentEndpointTest extends TestCase
{
    use BuildsBlockSections;
    use BuildsFreshmen;
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'https://www.grc-enrollment.tech']);
    }

    public function test_the_cashier_confirms_the_payment_and_the_freshman_waits_for_the_registrar_in_the_first_block(): void
    {
        Mail::fake();
        [$profile] = $this->scenario();

        $this->withToken($this->cashierToken())
            ->postJson("/api/v1/student-profiles/{$profile->id}/freshman-payment", ['amount' => '1000.00', 'external_reference' => 'OR-9'])
            ->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.type', 'freshman_payment')
            ->assertJsonPath('data.outcome', 'awaiting_registrar_approval')
            ->assertJsonPath('data.block_code', 'IT101')
            ->assertJsonPath('data.enrollment.status', 'pending_registrar_approval')
            ->assertJsonPath('data.held_amount', '1000.00')
            ->assertJsonPath('data.student.student_number', $profile->student_number);
    }

    public function test_the_account_setup_email_goes_out_after_payment_but_the_enrolled_email_does_not(): void
    {
        Mail::fake();
        [$profile] = $this->scenario();

        $this->withToken($this->cashierToken())
            ->postJson("/api/v1/student-profiles/{$profile->id}/freshman-payment", ['amount' => '1000.00'])
            ->assertCreated();

        Mail::assertSent(StudentAccountSetupMail::class, fn (StudentAccountSetupMail $mail): bool => $mail->hasTo($profile->user->email));
        // Not enrolled until the Registrar approves, so no "You're enrolled" email yet (2026-10-04).
        Mail::assertNotSent(EnrollmentConfirmedMail::class);
    }

    public function test_when_every_block_is_full_the_payment_is_held_and_the_account_setup_email_still_goes_out(): void
    {
        Mail::fake();
        $profile = $this->freshmanProfile();
        $term = $this->ongoingTerm();
        $plan = $this->yearOnePlan($term, $profile->curriculum);
        $this->fullBlock($term, $plan, 'IT101', 'ITP1');
        $this->issueFreshmanTicket($profile);

        $this->withToken($this->cashierToken())
            ->postJson("/api/v1/student-profiles/{$profile->id}/freshman-payment", ['amount' => '1000.00'])
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'awaiting_section')
            ->assertJsonPath('data.enrollment', null)
            ->assertJsonPath('data.held_amount', '1000.00');

        // They need the account to see the new section when it opens, but there is no COR to announce.
        Mail::assertSent(StudentAccountSetupMail::class);
        Mail::assertNotSent(EnrollmentConfirmedMail::class);
        self::assertSame(1, AccountPayment::query()->count());
    }

    public function test_confirming_again_is_a_200_that_sends_nothing_and_changes_nothing(): void
    {
        Mail::fake();
        [$profile] = $this->scenario();
        $token = $this->cashierToken();
        $this->withToken($token)->postJson("/api/v1/student-profiles/{$profile->id}/freshman-payment", ['amount' => '1000.00'])->assertCreated();

        $this->withToken($token)->postJson("/api/v1/student-profiles/{$profile->id}/freshman-payment", ['amount' => '1000.00'])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'awaiting_registrar_approval');

        Mail::assertSentCount(1); // the account setup email, once; no enrolled email before the Registrar approves
        self::assertSame(0, Payment::query()->count());
        self::assertSame(1, AccountPayment::query()->count());
        self::assertSame(1, Enrollment::query()->count());
    }

    public function test_a_payment_below_one_thousand_or_a_missing_amount_is_refused(): void
    {
        [$profile] = $this->scenario();
        $token = $this->cashierToken();

        $this->withToken($token)->postJson("/api/v1/student-profiles/{$profile->id}/freshman-payment", ['amount' => '999.99'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->withToken($token)->postJson("/api/v1/student-profiles/{$profile->id}/freshman-payment", [])
            ->assertUnprocessable();

        self::assertSame(0, Payment::query()->count());
    }

    public function test_only_accounting_staff_can_confirm_a_freshman_payment(): void
    {
        [$profile] = $this->scenario();
        // Before any token is attached: `withToken` stays on the test for every later request.
        $this->postJson("/api/v1/student-profiles/{$profile->id}/freshman-payment", ['amount' => '1000.00'])->assertUnauthorized();

        foreach ([UserRole::AdmissionStaff, UserRole::RegistrarStaff, UserRole::RegistrarHead, UserRole::Student] as $role) {
            $token = $this->tokenFor($role, 'role.'.$role->value.'@grc.test');
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->postJson("/api/v1/student-profiles/{$profile->id}/freshman-payment", ['amount' => '1000.00'])->assertForbidden();
            $this->app['auth']->forgetGuards();
        }

        self::assertSame(0, Payment::query()->count());
    }

    public function test_a_student_who_does_not_pay_first_cannot_use_it(): void
    {
        // A Returnee follows the ordinary flow; a Transferee now pays first like a freshman (ADR 0043, amended 2026-10-05).
        $returnee = $this->freshmanProfile('2026-10-00002', StudentType::Returnee);

        $this->withToken($this->cashierToken())
            ->postJson("/api/v1/student-profiles/{$returnee->id}/freshman-payment", ['amount' => '1000.00'])
            ->assertUnprocessable();
    }

    public function test_an_unknown_student_is_not_found(): void
    {
        $this->withToken($this->cashierToken())
            ->postJson('/api/v1/student-profiles/999999/freshman-payment', ['amount' => '1000.00'])
            ->assertNotFound();
    }

    public function test_the_preview_names_the_block_the_freshman_will_get_and_the_estimated_total(): void
    {
        [$profile, $term, $plan] = $this->scenario();
        $this->blockSection($term, $plan, 'IT102', 'ITP2');

        $this->withToken($this->cashierToken())
            ->getJson("/api/v1/student-profiles/{$profile->id}/freshman-payment-preview")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.type', 'freshman_payment_preview')
            ->assertJsonPath('data.can_enroll', true)
            ->assertJsonPath('data.block.block_code', 'IT101')
            ->assertJsonPath('data.block.seats_remaining', 40)
            ->assertJsonPath('data.minimum_amount', '1000.00')
            ->assertJsonPath('data.already_confirmed', false)
            ->assertJsonPath('data.ticket_number', 'Q001');
    }

    public function test_the_preview_says_so_when_every_block_is_full(): void
    {
        $profile = $this->freshmanProfile();
        $term = $this->ongoingTerm();
        $plan = $this->yearOnePlan($term, $profile->curriculum);
        $this->fullBlock($term, $plan, 'IT101', 'ITP1');
        $this->issueFreshmanTicket($profile);

        $this->withToken($this->cashierToken())
            ->getJson("/api/v1/student-profiles/{$profile->id}/freshman-payment-preview")
            ->assertOk()
            ->assertJsonPath('data.can_enroll', false)
            ->assertJsonPath('data.block', null)
            ->assertJsonPath('data.estimated_total', null);
    }

    public function test_the_preview_changes_nothing(): void
    {
        [$profile] = $this->scenario();

        $this->withToken($this->cashierToken())->getJson("/api/v1/student-profiles/{$profile->id}/freshman-payment-preview")->assertOk();

        self::assertSame(0, Enrollment::query()->count());
        self::assertNull($profile->fresh()->freshman_payment_confirmed_at);
    }

    /**
     * @return array{0: StudentProfile, 1: AcademicTerm, 2: AcademicTermSectionPlan}
     */
    private function scenario(): array
    {
        $profile = $this->freshmanProfile();
        $term = $this->ongoingTerm();
        $plan = $this->yearOnePlan($term, $profile->curriculum);
        $this->blockSection($term, $plan, 'IT101', 'ITP1');
        $this->issueFreshmanTicket($profile);

        return [$profile, $term, $plan];
    }

    private function cashierToken(): string
    {
        return $this->tokenFor(UserRole::AccountingStaff, 'cashier@grc.test');
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
