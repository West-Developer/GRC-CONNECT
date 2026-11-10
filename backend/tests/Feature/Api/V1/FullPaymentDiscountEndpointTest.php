<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\AssessmentItem;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\EnrollmentDocument;
use App\Models\Payment;
use App\Models\PaymentDiscountSetting;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR 0046 for a student who already has an assessment: the Cashier's payment quote and
 * "Pay in full". The assessment is 1,050.00 (750.00 tuition plus 300.00 miscellaneous), so a 10%
 * discount is 105.00 and the net is 945.00.
 */
final class FullPaymentDiscountEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Enrollment $enrollment;

    private Assessment $assessment;

    protected function setUp(): void
    {
        parent::setUp();

        $term = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
        $program = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS', 'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
        $user = User::create([
            'name' => 'Test Student', 'email' => 'student.fullpay@grc.test', 'password' => 'secret-password-1',
            'role' => UserRole::Student, 'status' => UserStatus::Active,
        ]);
        $student = StudentProfile::create([
            'user_id' => $user->id, 'student_number' => '2026-0001', 'program_id' => $program->id,
            'curriculum_id' => $curriculum->id, 'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted, 'academic_standing' => AcademicStanding::Good,
        ]);
        $this->enrollment = Enrollment::create([
            'student_id' => $student->id, 'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::PendingPayment, 'total_units' => 3, 'submitted_at' => now(),
        ]);
        $this->assessment = Assessment::create([
            'enrollment_id' => $this->enrollment->id, 'total_amount' => '1050.00', 'currency' => 'PHP', 'assessed_at' => now(),
        ]);
        AssessmentItem::create([
            'assessment_id' => $this->assessment->id, 'category' => 'tuition', 'label' => 'Tuition',
            'quantity' => '3.0', 'unit_amount' => '250.00', 'amount' => '750.00',
        ]);
        AssessmentItem::create([
            'assessment_id' => $this->assessment->id, 'category' => 'miscellaneous', 'label' => 'Registration', 'amount' => '300.00',
        ]);
    }

    private function actAs(UserRole $role): User
    {
        $user = User::create([
            'name' => 'Test '.$role->value, 'email' => 'actor.'.$role->value.'.fullpay@grc.test', 'password' => 'secret-password-1',
            'role' => $role, 'status' => UserStatus::Active,
        ]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        return $user;
    }

    private function quoteUrl(): string
    {
        return "/api/v1/enrollments/{$this->enrollment->id}/payment-quote";
    }

    private function payUrl(): string
    {
        return "/api/v1/enrollments/{$this->enrollment->id}/payment";
    }

    /** @return list<array<string, mixed>> */
    private function discountLines(): array
    {
        return array_values(array_filter(
            $this->assessment->refresh()->items->map->toArray()->all(),
            fn (array $item): bool => $item['category'] === 'full_payment_discount',
        ));
    }

    private function addScholarship(): void
    {
        AssessmentItem::create([
            'assessment_id' => $this->assessment->id, 'category' => 'scholarship_discount', 'label' => 'Scholarship discount (40%)',
            'quantity' => '40.0', 'amount' => '-420.00',
        ]);
        $this->assessment->update(['total_amount' => '630.00']);
    }

    // --- The quote ---------------------------------------------------------

    public function test_the_quote_offers_the_discount_on_the_whole_assessment(): void
    {
        $this->actAs(UserRole::AccountingStaff);

        $this->getJson($this->quoteUrl())
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.type', 'payment_quote')
            ->assertJsonPath('data.total_amount', '1050.00')
            ->assertJsonPath('data.full_payment.applies', true)
            ->assertJsonPath('data.full_payment.percentage', '10.0')
            ->assertJsonPath('data.full_payment.discount_amount', '105.00')
            ->assertJsonPath('data.full_payment.net_total', '945.00')
            ->assertJsonPath('data.full_payment.reason', null);
    }

    public function test_the_quote_says_why_a_scholar_gets_no_discount(): void
    {
        $this->addScholarship();
        $this->actAs(UserRole::AccountingStaff);

        $this->getJson($this->quoteUrl())
            ->assertOk()
            ->assertJsonPath('data.total_amount', '630.00')
            ->assertJsonPath('data.full_payment.applies', false)
            ->assertJsonPath('data.full_payment.reason', 'scholar')
            ->assertJsonPath('data.full_payment.net_total', '630.00')
            ->assertJsonPath('data.full_payment.discount_amount', null);
    }

    public function test_the_quote_says_when_the_window_has_closed(): void
    {
        PaymentDiscountSetting::current()->update(['valid_until' => '2000-01-01']);
        $this->actAs(UserRole::AccountingStaff);

        $this->getJson($this->quoteUrl())
            ->assertOk()
            ->assertJsonPath('data.full_payment.applies', false)
            ->assertJsonPath('data.full_payment.reason', 'expired')
            ->assertJsonPath('data.full_payment.net_total', '1050.00');
    }

    public function test_only_accounting_staff_may_ask_for_a_quote(): void
    {
        $this->getJson($this->quoteUrl())->assertUnauthorized();

        foreach ([UserRole::Student, UserRole::RegistrarHead, UserRole::RegistrarStaff, UserRole::Dean] as $role) {
            $this->actAs($role);
            $this->getJson($this->quoteUrl())->assertForbidden();
        }
    }

    public function test_a_quote_is_refused_once_the_enrollment_is_no_longer_pending_payment(): void
    {
        $this->enrollment->update(['status' => EnrollmentStatus::Enrolled]);
        $this->actAs(UserRole::AccountingStaff);

        $this->getJson($this->quoteUrl())->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    // --- Pay in full -------------------------------------------------------

    public function test_paying_in_full_takes_the_discount_and_collects_the_net(): void
    {
        $actor = $this->actAs(UserRole::AccountingStaff);

        $this->postJson($this->payUrl(), ['payment_type' => 'full', 'quoted_amount' => '945.00'])->assertCreated();

        $payment = Payment::query()->where('enrollment_id', $this->enrollment->id)->sole();
        self::assertSame('945.00', $payment->amount);
        self::assertFalse($payment->promissory_note_on_file);
        self::assertSame('945.00', $this->assessment->refresh()->total_amount);
        self::assertSame(EnrollmentStatus::Enrolled, $this->enrollment->refresh()->status);

        $lines = $this->discountLines();
        self::assertCount(1, $lines);
        self::assertSame('Full-payment discount (10%)', $lines[0]['label']);
        self::assertSame('-105.00', $lines[0]['amount']);
        self::assertSame('10.0', $lines[0]['quantity']);
        // Tuition and miscellaneous are untouched.
        $this->assertDatabaseHas('assessment_items', ['assessment_id' => $this->assessment->id, 'category' => 'tuition', 'amount' => '750.00']);
        $this->assertDatabaseHas('assessment_items', ['assessment_id' => $this->assessment->id, 'category' => 'miscellaneous', 'amount' => '300.00']);

        $audit = AuditLog::query()->where('action', AuditAction::ASSESSMENT_FULL_PAYMENT_DISCOUNT_APPLIED)->sole();
        self::assertSame($actor->id, $audit->actor_user_id);
        self::assertSame($this->assessment->id, $audit->auditable_id);
        self::assertSame('1050.00', $audit->before_values['total_amount'] ?? null);
        self::assertSame('945.00', $audit->after_values['total_amount'] ?? null);
        self::assertSame('10.0', $audit->after_values['percentage'] ?? null);
        self::assertSame('105.00', $audit->after_values['discount_amount'] ?? null);
        self::assertSame('cashier_payment', $audit->after_values['source'] ?? null);
    }

    public function test_the_cor_snapshot_lists_the_discount_and_the_net_grand_total(): void
    {
        $this->actAs(UserRole::AccountingStaff);

        $this->postJson($this->payUrl(), ['payment_type' => 'full', 'quoted_amount' => '945.00'])->assertCreated();

        $fees = EnrollmentDocument::query()->where('enrollment_id', $this->enrollment->id)->sole()->snapshot['fees'] ?? [];
        self::assertSame('-105.00', $fees['full_payment_discount'][0]['amount']);
        self::assertSame('Full-payment discount (10%)', $fees['full_payment_discount'][0]['label']);
        self::assertSame('-105.00', $fees['total_full_payment_discount']);
        self::assertSame('945.00', $fees['grand_total']);
        self::assertSame('full_payment', $fees['payment_status']);
        self::assertSame('0.00', $fees['remaining_balance']);
        // tuition + other fees + the discount add up to the grand total.
        self::assertSame($fees['grand_total'], bcadd(bcadd($fees['total_tuition'], $fees['total_other_fees'], 2), $fees['total_full_payment_discount'], 2));
    }

    public function test_paying_in_full_outside_the_window_collects_the_plain_total(): void
    {
        PaymentDiscountSetting::current()->update(['valid_until' => '2000-01-01']);
        $this->actAs(UserRole::AccountingStaff);

        $this->postJson($this->payUrl(), ['payment_type' => 'full', 'quoted_amount' => '1050.00'])->assertCreated();

        self::assertSame('1050.00', Payment::query()->where('enrollment_id', $this->enrollment->id)->sole()->amount);
        self::assertSame([], $this->discountLines());
        self::assertSame('1050.00', $this->assessment->refresh()->total_amount);
        self::assertSame(0, AuditLog::query()->where('action', AuditAction::ASSESSMENT_FULL_PAYMENT_DISCOUNT_APPLIED)->count());
    }

    public function test_a_scholar_paying_in_full_gets_no_second_discount(): void
    {
        $this->addScholarship();
        $this->actAs(UserRole::AccountingStaff);

        $this->postJson($this->payUrl(), ['payment_type' => 'full', 'quoted_amount' => '630.00'])->assertCreated();

        self::assertSame('630.00', Payment::query()->where('enrollment_id', $this->enrollment->id)->sole()->amount);
        self::assertSame([], $this->discountLines());
        self::assertSame('630.00', $this->assessment->refresh()->total_amount);
    }

    public function test_a_quote_the_cashier_saw_that_is_no_longer_true_is_refused_and_writes_nothing(): void
    {
        $this->actAs(UserRole::AccountingStaff);
        // The Registrar Head raised the discount after the Cashier opened the modal at 945.00.
        PaymentDiscountSetting::current()->update(['percentage' => '20.0']);

        $this->postJson($this->payUrl(), ['payment_type' => 'full', 'quoted_amount' => '945.00'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        self::assertSame(0, Payment::query()->count());
        self::assertSame([], $this->discountLines());
        self::assertSame('1050.00', $this->assessment->refresh()->total_amount);
        self::assertSame(EnrollmentStatus::PendingPayment, $this->enrollment->refresh()->status);
    }

    public function test_confirming_again_changes_nothing(): void
    {
        $this->actAs(UserRole::AccountingStaff);
        $this->postJson($this->payUrl(), ['payment_type' => 'full', 'quoted_amount' => '945.00'])->assertCreated();

        // A repeat is idempotent even though the discount has moved on since (the quote no longer matches).
        PaymentDiscountSetting::current()->update(['percentage' => '20.0']);
        $this->postJson($this->payUrl(), ['payment_type' => 'full', 'quoted_amount' => '945.00'])->assertOk();

        self::assertSame(1, Payment::query()->count());
        self::assertCount(1, $this->discountLines());
        self::assertSame('945.00', $this->assessment->refresh()->total_amount);
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::ASSESSMENT_FULL_PAYMENT_DISCOUNT_APPLIED)->count());
    }

    public function test_a_full_payment_needs_the_quoted_amount_and_may_not_carry_an_amount(): void
    {
        $this->actAs(UserRole::AccountingStaff);

        $this->postJson($this->payUrl(), ['payment_type' => 'full'])->assertUnprocessable();
        // An amount that is otherwise valid (at least 1,000) is still refused: a full payment collects the quoted net.
        $this->postJson($this->payUrl(), ['payment_type' => 'full', 'quoted_amount' => '945.00', 'amount' => '1050.00'])->assertUnprocessable();
        self::assertSame(0, Payment::query()->count());
    }

    public function test_a_quoted_amount_that_is_not_a_plain_decimal_is_refused_not_crashed(): void
    {
        $this->actAs(UserRole::AccountingStaff);

        // "1e3" is numeric to PHP but makes bcmath throw; "945.005" would be truncated, not rounded.
        foreach (['1e3', '945.005', '-945.00', 'abc'] as $quotedAmount) {
            $this->postJson($this->payUrl(), ['payment_type' => 'full', 'quoted_amount' => $quotedAmount])
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        self::assertSame(0, Payment::query()->count());
    }

    // --- Installment and legacy requests -----------------------------------

    public function test_an_installment_has_no_discount_and_no_extra_fee(): void
    {
        $this->actAs(UserRole::AccountingStaff);

        $this->postJson($this->payUrl(), ['payment_type' => 'installment', 'amount' => '1000.00', 'promissory_note_on_file' => true])->assertCreated();

        self::assertSame('1000.00', Payment::query()->where('enrollment_id', $this->enrollment->id)->sole()->amount);
        self::assertSame('1050.00', $this->assessment->refresh()->total_amount);
        self::assertSame([], $this->discountLines());
        // Still exactly the two original lines: no fee or surcharge line was added.
        self::assertSame(2, $this->assessment->items()->count());
    }

    public function test_an_installment_below_the_total_needs_no_promissory_note_without_a_previous_bill(): void
    {
        $this->actAs(UserRole::AccountingStaff);

        // 2026-10-13: a note is only for a student with a previous bill (`PromissoryNoteRule`); this one has none.
        $this->postJson($this->payUrl(), ['payment_type' => 'installment', 'amount' => '1000.00'])->assertCreated();

        $payment = Payment::query()->where('enrollment_id', $this->enrollment->id)->sole();
        self::assertSame('1000.00', $payment->amount);
        self::assertFalse($payment->promissory_note_on_file);
        self::assertSame([], $this->discountLines());
    }

    public function test_an_installment_without_an_amount_is_refused(): void
    {
        $this->actAs(UserRole::AccountingStaff);

        $this->postJson($this->payUrl(), ['payment_type' => 'installment'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertSame(0, Payment::query()->count());
    }

    public function test_a_request_without_a_payment_type_behaves_as_before(): void
    {
        $this->actAs(UserRole::AccountingStaff);

        $this->postJson($this->payUrl(), [])->assertCreated();

        self::assertSame('1050.00', Payment::query()->where('enrollment_id', $this->enrollment->id)->sole()->amount);
        self::assertSame([], $this->discountLines());
    }
}
