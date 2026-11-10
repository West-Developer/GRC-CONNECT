<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\FinancialStatus;
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
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR 0025: the Cashier's Payee/Scholar choice. A scholarship (100%, 40% or 20%)
 * is a negative line on the enrollment's assessment, computed on the whole
 * assessment (tuition + miscellaneous), so Amount due, the payment modal and
 * the COR all show the net.
 */
final class ScholarshipDiscountEndpointTest extends TestCase
{
    use RefreshDatabase;

    private StudentProfile $student;

    private Enrollment $enrollment;

    private Assessment $assessment;

    protected function setUp(): void
    {
        parent::setUp();

        $term = AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        $program = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS', 'effective_school_year' => '2026-2027',
            'status' => CurriculumStatus::Active,
        ]);
        $user = User::create([
            'name' => 'Test Student', 'email' => 'student.scholar@grc.test', 'password' => 'secret-password-1',
            'role' => UserRole::Student, 'status' => UserStatus::Active,
        ]);
        $this->student = StudentProfile::create([
            'user_id' => $user->id, 'student_number' => '2026-0001', 'program_id' => $program->id,
            'curriculum_id' => $curriculum->id, 'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted, 'academic_standing' => AcademicStanding::Good,
        ]);
        $this->enrollment = Enrollment::create([
            'student_id' => $this->student->id, 'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::PendingPayment, 'total_units' => 3, 'submitted_at' => now(),
        ]);
        // Tuition 3 units x 250.00 = 750.00, plus 300.00 of miscellaneous fees: 1,050.00.
        $this->assessment = Assessment::create([
            'enrollment_id' => $this->enrollment->id, 'total_amount' => '1050.00', 'currency' => 'PHP', 'assessed_at' => now(),
        ]);
        AssessmentItem::create([
            'assessment_id' => $this->assessment->id, 'category' => 'tuition', 'label' => 'Tuition',
            'quantity' => '3.0', 'unit_amount' => '250.00', 'amount' => '750.00',
        ]);
        AssessmentItem::create([
            'assessment_id' => $this->assessment->id, 'category' => 'miscellaneous', 'label' => 'Registration',
            'amount' => '300.00',
        ]);
    }

    private function actAs(UserRole $role): User
    {
        $user = User::create([
            'name' => 'Test '.$role->value, 'email' => 'actor.'.$role->value.'.scholar@grc.test', 'password' => 'secret-password-1',
            'role' => $role, 'status' => UserStatus::Active,
        ]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        return $user;
    }

    private function url(): string
    {
        return "/api/v1/enrollments/{$this->enrollment->id}/scholarship-discount";
    }

    /** @return list<array<string, mixed>> */
    private function discountLines(): array
    {
        return array_values(array_filter(
            $this->assessment->refresh()->items->map->toArray()->all(),
            fn (array $item): bool => $item['category'] === 'scholarship_discount',
        ));
    }

    // --- Authorization ----------------------------------------------------

    public function test_anonymous_requests_are_unauthenticated(): void
    {
        $this->putJson($this->url(), ['percentage' => 40])->assertUnauthorized();
        $this->deleteJson($this->url())->assertUnauthorized();
    }

    public function test_only_accounting_staff_may_change_a_scholarship(): void
    {
        foreach ([UserRole::Student, UserRole::RegistrarHead, UserRole::RegistrarStaff, UserRole::Dean] as $role) {
            $this->actAs($role);
            $this->putJson($this->url(), ['percentage' => 40])->assertForbidden();
            $this->deleteJson($this->url())->assertForbidden();
        }

        self::assertSame('1050.00', $this->assessment->refresh()->total_amount);
        self::assertSame([], $this->discountLines());
    }

    // --- Applying ----------------------------------------------------------

    public function test_a_partial_scholarship_becomes_a_negative_line_on_the_whole_assessment(): void
    {
        $this->actAs(UserRole::AccountingStaff);

        $response = $this->putJson($this->url(), ['percentage' => 40])->assertOk();

        $response->assertJsonPath('data.assessment.total_amount', '630.00')
            ->assertJsonPath('data.student_financial_status', 'scholar');
        $lines = collect($response->json('data.assessment.items'))->where('category', 'scholarship_discount')->values();
        self::assertCount(1, $lines);
        self::assertSame('Scholarship discount (40%)', $lines[0]['label']);
        self::assertSame('-420.00', $lines[0]['amount']);
        self::assertSame('40.0', $lines[0]['quantity']);
        self::assertSame(FinancialStatus::Scholar, $this->student->refresh()->financial_status);

        // The tuition and miscellaneous lines are untouched.
        $this->assertDatabaseHas('assessment_items', ['assessment_id' => $this->assessment->id, 'category' => 'tuition', 'amount' => '750.00']);
        $this->assertDatabaseHas('assessment_items', ['assessment_id' => $this->assessment->id, 'category' => 'miscellaneous', 'amount' => '300.00']);
    }

    public function test_every_tier_takes_its_percentage_off_the_assessment(): void
    {
        $this->actAs(UserRole::AccountingStaff);

        foreach ([100 => ['0.00', '-1050.00'], 40 => ['630.00', '-420.00'], 20 => ['840.00', '-210.00']] as $percentage => [$net, $discount]) {
            $this->putJson($this->url(), ['percentage' => $percentage])->assertOk()
                ->assertJsonPath('data.assessment.total_amount', $net);
            self::assertSame($discount, $this->discountLines()[0]['amount']);
        }
    }

    public function test_changing_the_tier_replaces_the_line_and_never_compounds(): void
    {
        $this->actAs(UserRole::AccountingStaff);

        $this->putJson($this->url(), ['percentage' => 40])->assertOk();
        $this->putJson($this->url(), ['percentage' => 20])->assertOk()
            ->assertJsonPath('data.assessment.total_amount', '840.00');

        self::assertCount(1, $this->discountLines());
        self::assertSame('Scholarship discount (20%)', $this->discountLines()[0]['label']);
    }

    public function test_applying_the_same_tier_twice_is_idempotent(): void
    {
        $this->actAs(UserRole::AccountingStaff);

        $first = $this->putJson($this->url(), ['percentage' => 40])->assertOk()->json('data.assessment');
        $second = $this->putJson($this->url(), ['percentage' => 40])->assertOk()->json('data.assessment');

        self::assertSame($first['total_amount'], $second['total_amount']);
        self::assertCount(1, $this->discountLines());
    }

    public function test_only_the_three_tiers_are_accepted(): void
    {
        $this->actAs(UserRole::AccountingStaff);

        $this->putJson($this->url(), [])->assertUnprocessable();
        $this->putJson($this->url(), ['percentage' => 50])->assertUnprocessable();
        $this->putJson($this->url(), ['percentage' => 'forty'])->assertUnprocessable();
        self::assertSame([], $this->discountLines());
    }

    public function test_applying_and_removing_are_audited(): void
    {
        $actor = $this->actAs(UserRole::AccountingStaff);

        $this->putJson($this->url(), ['percentage' => 40])->assertOk();
        $this->deleteJson($this->url())->assertOk();

        $applied = AuditLog::query()->where('action', AuditAction::ASSESSMENT_SCHOLARSHIP_APPLIED)->sole();
        self::assertSame($actor->id, $applied->actor_user_id);
        self::assertSame($this->assessment->id, $applied->auditable_id);
        self::assertSame(40, $applied->after_values['percentage']);
        self::assertSame('630.00', $applied->after_values['total_amount']);

        $removed = AuditLog::query()->where('action', AuditAction::ASSESSMENT_SCHOLARSHIP_REMOVED)->sole();
        self::assertSame('1050.00', $removed->after_values['total_amount']);
    }

    // --- Removing (the Regular payee choice) -------------------------------

    public function test_choosing_regular_payee_removes_the_discount_and_restores_the_total(): void
    {
        $this->actAs(UserRole::AccountingStaff);
        $this->putJson($this->url(), ['percentage' => 40])->assertOk();

        $this->deleteJson($this->url())->assertOk()
            ->assertJsonPath('data.assessment.total_amount', '1050.00')
            ->assertJsonPath('data.student_financial_status', 'payee');

        self::assertSame([], $this->discountLines());
        self::assertSame(FinancialStatus::Payee, $this->student->refresh()->financial_status);
    }

    public function test_removing_when_there_is_no_discount_changes_nothing_but_the_classification(): void
    {
        $this->actAs(UserRole::AccountingStaff);

        $this->deleteJson($this->url())->assertOk()
            ->assertJsonPath('data.assessment.total_amount', '1050.00');

        self::assertSame(FinancialStatus::Payee, $this->student->refresh()->financial_status);
    }

    // --- When it may change --------------------------------------------------

    public function test_it_is_refused_once_the_enrollment_is_no_longer_pending_payment(): void
    {
        $this->actAs(UserRole::AccountingStaff);
        $this->enrollment->update(['status' => EnrollmentStatus::Enrolled]);

        $this->putJson($this->url(), ['percentage' => 40])->assertUnprocessable();
        $this->deleteJson($this->url())->assertUnprocessable();
        self::assertSame('1050.00', $this->assessment->refresh()->total_amount);
    }

    public function test_it_is_refused_after_a_payment_has_been_confirmed(): void
    {
        $actor = $this->actAs(UserRole::AccountingStaff);
        Payment::create([
            'enrollment_id' => $this->enrollment->id, 'confirmed_by' => $actor->id, 'amount' => '1050.00',
            'promissory_note_on_file' => false, 'confirmed_at' => now(),
        ]);

        $this->putJson($this->url(), ['percentage' => 40])->assertUnprocessable();
        self::assertSame([], $this->discountLines());
    }

    public function test_it_is_refused_when_there_is_no_assessment(): void
    {
        $this->actAs(UserRole::AccountingStaff);
        $this->assessment->delete();

        $this->putJson($this->url(), ['percentage' => 40])->assertUnprocessable();
    }

    // --- Payment and COR -------------------------------------------------------

    public function test_confirming_after_a_partial_scholarship_pays_the_net_and_the_cor_shows_the_discount(): void
    {
        $this->actAs(UserRole::AccountingStaff);
        $this->putJson($this->url(), ['percentage' => 40])->assertOk();

        // Net 630.00 is below the 1,000.00 minimum, so no amount is sent and the
        // payment falls back to the (already discounted) assessment total.
        $this->postJson("/api/v1/enrollments/{$this->enrollment->id}/payment", [])->assertSuccessful();

        self::assertSame('630.00', Payment::query()->where('enrollment_id', $this->enrollment->id)->sole()->amount);
        $fees = EnrollmentDocument::query()->where('enrollment_id', $this->enrollment->id)->sole()->snapshot['fees'];
        self::assertSame('630.00', $fees['grand_total']);
        self::assertSame('-420.00', $fees['total_scholarship_discount']);
        self::assertSame('Scholarship discount (40%)', $fees['scholarship_discount'][0]['label']);
        self::assertSame('-420.00', $fees['scholarship_discount'][0]['amount']);
        self::assertSame('0.00', $fees['remaining_balance']);
        self::assertSame('full_payment', $fees['payment_status']);
        self::assertSame(EnrollmentStatus::Enrolled, $this->enrollment->refresh()->status);
    }

    public function test_a_full_scholarship_confirms_with_no_amount_and_still_generates_the_cor(): void
    {
        $this->actAs(UserRole::AccountingStaff);
        $this->putJson($this->url(), ['percentage' => 100])->assertOk()
            ->assertJsonPath('data.assessment.total_amount', '0.00');

        $this->postJson("/api/v1/enrollments/{$this->enrollment->id}/payment", [])->assertSuccessful();

        self::assertSame('0.00', Payment::query()->where('enrollment_id', $this->enrollment->id)->sole()->amount);
        self::assertSame(EnrollmentStatus::Enrolled, $this->enrollment->refresh()->status);
        $fees = EnrollmentDocument::query()->where('enrollment_id', $this->enrollment->id)->sole()->snapshot['fees'];
        self::assertSame('0.00', $fees['grand_total']);
        self::assertSame('0.00', $fees['remaining_balance']);
        self::assertSame('full_payment', $fees['payment_status']);
    }

    public function test_a_cor_without_a_scholarship_has_no_discount_lines(): void
    {
        $this->actAs(UserRole::AccountingStaff);

        $this->postJson("/api/v1/enrollments/{$this->enrollment->id}/payment", ['amount' => 1050])->assertSuccessful();

        $fees = EnrollmentDocument::query()->where('enrollment_id', $this->enrollment->id)->sole()->snapshot['fees'];
        self::assertSame([], $fees['scholarship_discount']);
        self::assertSame('0.00', $fees['total_scholarship_discount']);
    }

    public function test_the_cor_snapshot_records_the_scholarship_discount_and_the_printed_cor_lists_it_without_any_payment(): void
    {
        $this->actAs(UserRole::AccountingStaff);
        $this->putJson($this->url(), ['percentage' => 40])->assertOk();
        $this->postJson("/api/v1/enrollments/{$this->enrollment->id}/payment", [])->assertSuccessful();
        $document = EnrollmentDocument::query()->where('enrollment_id', $this->enrollment->id)->sole();

        $discount = $document->snapshot['fees']['scholarship_discount'][0] ?? null;
        self::assertNotNull($discount);
        self::assertSame('Scholarship discount (40%)', $discount['label']);
        self::assertSame('-420.00', $discount['amount']);

        $html = view('pdf.certificate-of-registration', [
            'document' => $document,
            'snapshot' => $document->snapshot,
        ])->render();

        // The printed COR is the bill: the fee list and the discount line are on it (stakeholder Doc 20),
        // but never what was paid or the balance.
        self::assertStringContainsString('Assessment of Fees', $html);
        self::assertStringContainsString('Scholarship discount (40%)', $html);
        self::assertStringContainsString('-&#8369;420.00', $html);
        self::assertStringNotContainsString('AMOUNT PAID', $html);
        self::assertStringNotContainsString('REMAINING BALANCE', $html);
    }

    // --- Adjust fees keeps the discount consistent -----------------------------

    public function test_adjusting_fees_recomputes_an_existing_scholarship_on_the_new_total(): void
    {
        $this->actAs(UserRole::AccountingStaff);
        $this->putJson($this->url(), ['percentage' => 40])->assertOk();
        $tuition = $this->assessment->items()->where('category', 'tuition')->sole();
        $misc = $this->assessment->items()->where('category', 'miscellaneous')->sole();

        // Tuition 3 x 300.00 = 900.00, plus 300.00 of fees: base 1,200.00, 40% = 480.00.
        $this->patchJson("/api/v1/enrollments/{$this->enrollment->id}/assessment", [
            'reason' => 'Corrected tuition rate',
            'items' => [
                ['id' => $tuition->id, 'unit_amount' => '300.00'],
                ['id' => $misc->id, 'amount' => '300.00'],
            ],
        ])->assertOk()->assertJsonPath('data.assessment.total_amount', '720.00');

        self::assertSame('-480.00', $this->discountLines()[0]['amount']);
    }

    public function test_the_discount_line_cannot_be_edited_through_adjust_fees(): void
    {
        $this->actAs(UserRole::AccountingStaff);
        $this->putJson($this->url(), ['percentage' => 40])->assertOk();
        $tuition = $this->assessment->items()->where('category', 'tuition')->sole();
        $misc = $this->assessment->items()->where('category', 'miscellaneous')->sole();
        $discount = $this->assessment->items()->where('category', 'scholarship_discount')->sole();

        $this->patchJson("/api/v1/enrollments/{$this->enrollment->id}/assessment", [
            'reason' => 'Trying to edit the discount',
            'items' => [
                ['id' => $tuition->id, 'unit_amount' => '250.00'],
                ['id' => $misc->id, 'amount' => '300.00'],
                ['id' => $discount->id, 'amount' => '-1.00'],
            ],
        ])->assertUnprocessable();

        self::assertSame('-420.00', $this->discountLines()[0]['amount']);
    }
}
