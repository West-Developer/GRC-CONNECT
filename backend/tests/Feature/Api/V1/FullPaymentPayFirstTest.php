<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Billing\FullPaymentDiscountPolicy;
use App\Domain\Identity\StudentType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\AccountPayment;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\EnrollmentDocument;
use App\Models\FeeSchedule;
use App\Models\Payment;
use App\Models\PaymentDiscountSetting;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BuildsBlockSections;
use Tests\Support\BuildsFreshmen;
use Tests\TestCase;

/**
 * ADR 0046 for a pay-first student: the Cashier quotes the discounted estimate, the percentage
 * is frozen on the profile, and the Registrar's approval applies it to the real assessment only
 * if the held credit covers it.
 */
final class FullPaymentPayFirstTest extends TestCase
{
    use BuildsBlockSections;
    use BuildsFreshmen;
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['app.frontend_url' => 'https://www.grc-enrollment.tech']);
    }

    /** @return array{0: StudentProfile} */
    private function scenario(): array
    {
        $profile = $this->freshmanProfile();
        $term = $this->ongoingTerm();
        $plan = $this->yearOnePlan($term, $profile->curriculum);
        $this->blockSection($term, $plan, 'IT101', 'ITP1');
        $this->issueFreshmanTicket($profile);

        return [$profile];
    }

    private function tokenFor(UserRole $role, string $email): string
    {
        User::query()->where('email', $email)->first() ?? User::create([
            'name' => 'Test '.$role->value, 'email' => $email, 'password' => self::PASSWORD,
            'role' => $role, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        $this->app['auth']->forgetGuards();

        $token = (string) $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => self::PASSWORD])->json('data.token');
        // The next request must resolve the user from this token, not from the previous request's user.
        $this->app['auth']->forgetGuards();

        return $token;
    }

    private function cashier(): string
    {
        return $this->tokenFor(UserRole::AccountingStaff, 'cashier@grc.test');
    }

    /** @return array{estimate: string, discount: string, net: string} */
    private function quoteFor(StudentProfile $profile): array
    {
        $estimate = (string) $this->withToken($this->cashier())
            ->getJson("/api/v1/student-profiles/{$profile->id}/freshman-payment-preview")
            ->assertOk()
            ->json('data.estimated_total');
        $discount = (new FullPaymentDiscountPolicy('10.0'))->discountFor($estimate);

        return ['estimate' => $estimate, 'discount' => $discount, 'net' => bcsub($estimate, $discount, 2)];
    }

    private function approve(Enrollment $enrollment): void
    {
        $registrar = $this->tokenFor(UserRole::RegistrarStaff, 'registrar.staff@grc.test');
        $this->withToken($registrar)
            ->patchJson("/api/v1/enrollments/{$enrollment->id}", ['action' => 'registrar_approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'enrolled');
    }

    public function test_the_preview_offers_the_discounted_estimate(): void
    {
        [$profile] = $this->scenario();
        $quote = $this->quoteFor($profile);

        $this->withToken($this->cashier())
            ->getJson("/api/v1/student-profiles/{$profile->id}/freshman-payment-preview")
            ->assertOk()
            ->assertJsonPath('data.full_payment.applies', true)
            ->assertJsonPath('data.full_payment.percentage', '10.0')
            ->assertJsonPath('data.full_payment.discount_amount', $quote['discount'])
            ->assertJsonPath('data.full_payment.net_total', $quote['net'])
            ->assertJsonPath('data.full_payment.reason', null);
    }

    public function test_a_full_payment_freezes_the_percentage_and_the_registrar_approval_applies_it(): void
    {
        [$profile] = $this->scenario();
        $quote = $this->quoteFor($profile);

        $this->withToken($this->cashier())
            ->postJson("/api/v1/student-profiles/{$profile->id}/freshman-payment", ['payment_type' => 'full', 'amount' => $quote['net']])
            ->assertCreated()
            ->assertJsonPath('data.held_amount', $quote['net']);

        self::assertSame('10.0', $profile->refresh()->full_payment_discount_percentage);
        self::assertSame($quote['net'], AccountPayment::query()->sole()->amount);

        // The Head lowers the discount afterwards; the student keeps what they were quoted.
        PaymentDiscountSetting::current()->update(['percentage' => '0.0']);

        $enrollment = Enrollment::query()->sole();
        $this->approve($enrollment);

        $assessment = Assessment::query()->where('enrollment_id', $enrollment->id)->sole();
        self::assertSame($quote['net'], $assessment->total_amount);
        $line = $assessment->items()->where('category', 'full_payment_discount')->sole();
        self::assertSame(bcmul($quote['discount'], '-1', 2), $line->amount);
        self::assertSame('Full-payment discount (10%)', $line->label);
        self::assertSame(1, Payment::query()->where('enrollment_id', $enrollment->id)->count());
        self::assertSame('0.00', Payment::query()->where('enrollment_id', $enrollment->id)->sole()->amount);

        $fees = EnrollmentDocument::query()->where('enrollment_id', $enrollment->id)->sole()->snapshot['fees'];
        self::assertSame(bcmul($quote['discount'], '-1', 2), $fees['total_full_payment_discount']);
        self::assertSame($quote['net'], $fees['grand_total']);
        self::assertSame('0.00', $fees['remaining_balance']);

        // The frozen percentage is used once and cleared, so it can never apply to a later term.
        self::assertNull($profile->refresh()->full_payment_discount_percentage);
    }

    public function test_a_stale_amount_is_refused_and_records_nothing(): void
    {
        [$profile] = $this->scenario();
        $quote = $this->quoteFor($profile);
        PaymentDiscountSetting::current()->update(['percentage' => '20.0']);

        $this->withToken($this->cashier())
            ->postJson("/api/v1/student-profiles/{$profile->id}/freshman-payment", ['payment_type' => 'full', 'amount' => $quote['net']])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        self::assertSame(0, AccountPayment::query()->count());
        self::assertNull($profile->refresh()->freshman_payment_confirmed_at);
        self::assertNull($profile->full_payment_discount_percentage);
    }

    public function test_an_installment_freezes_nothing_and_gets_no_discount(): void
    {
        [$profile] = $this->scenario();

        $this->withToken($this->cashier())
            ->postJson("/api/v1/student-profiles/{$profile->id}/freshman-payment", ['payment_type' => 'installment', 'amount' => '1000.00'])
            ->assertCreated();

        self::assertNull($profile->refresh()->full_payment_discount_percentage);

        $enrollment = Enrollment::query()->sole();
        $this->approve($enrollment);

        $assessment = Assessment::query()->where('enrollment_id', $enrollment->id)->sole();
        self::assertSame(0, $assessment->items()->where('category', 'full_payment_discount')->count());
    }

    public function test_when_the_real_assessment_costs_more_than_the_estimate_no_discount_is_given_and_the_enrollment_still_completes(): void
    {
        [$profile] = $this->scenario();
        $quote = $this->quoteFor($profile);

        $this->withToken($this->cashier())
            ->postJson("/api/v1/student-profiles/{$profile->id}/freshman-payment", ['payment_type' => 'full', 'amount' => $quote['net']])
            ->assertCreated();

        // Tuition goes up before the Registrar approves, so the credit no longer covers the discounted net.
        FeeSchedule::create(['category' => 'tuition', 'label' => 'Tuition Rate Per Unit', 'amount' => '500.00', 'is_active' => true, 'sort_order' => 1]);

        $enrollment = Enrollment::query()->sole();
        $this->approve($enrollment);

        $assessment = Assessment::query()->where('enrollment_id', $enrollment->id)->sole();
        self::assertSame(0, $assessment->items()->where('category', 'full_payment_discount')->count());
        self::assertSame(1, Payment::query()->where('enrollment_id', $enrollment->id)->count());
        self::assertNull($profile->refresh()->full_payment_discount_percentage);
        // The assessment is the full, undiscounted amount at the new rate, so it is higher than
        // what was paid; the student owes the difference exactly as an installment would.
        self::assertSame(1, bccomp($assessment->total_amount, $quote['net'], 2));
    }

    public function test_a_student_with_nothing_to_estimate_cannot_pay_in_full(): void
    {
        $profile = $this->freshmanProfile('2026-10-00002', StudentType::Freshman, 'irregular');
        $this->ongoingTerm();
        $this->issueFreshmanTicket($profile);

        $this->withToken($this->cashier())
            ->getJson("/api/v1/student-profiles/{$profile->id}/freshman-payment-preview")
            ->assertOk()
            ->assertJsonPath('data.full_payment.applies', false)
            ->assertJsonPath('data.full_payment.reason', 'no_estimate')
            ->assertJsonPath('data.full_payment.net_total', null);

        $this->withToken($this->cashier())
            ->postJson("/api/v1/student-profiles/{$profile->id}/freshman-payment", ['payment_type' => 'full', 'amount' => '5000.00'])
            ->assertUnprocessable();
        self::assertSame(0, AccountPayment::query()->count());
    }

    public function test_confirming_a_full_payment_again_changes_nothing(): void
    {
        [$profile] = $this->scenario();
        $quote = $this->quoteFor($profile);
        $token = $this->cashier();
        $body = ['payment_type' => 'full', 'amount' => $quote['net']];

        $this->withToken($token)->postJson("/api/v1/student-profiles/{$profile->id}/freshman-payment", $body)->assertCreated();
        $this->withToken($token)->postJson("/api/v1/student-profiles/{$profile->id}/freshman-payment", $body)->assertOk();

        self::assertSame(1, AccountPayment::query()->count());
        self::assertSame('10.0', $profile->refresh()->full_payment_discount_percentage);
    }
}
