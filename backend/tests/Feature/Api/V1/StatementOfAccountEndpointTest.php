<?php

namespace Tests\Feature\Api\V1;

use App\Actions\Billing\BuildStatementOfAccount;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\AccountPayment;
use App\Models\Assessment;
use App\Models\AssessmentItem;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stakeholder Doc 14 S25 (ADR 0036): the Statement of Account, per term, with
 * a running balance. A Student reads their own; Accounting Staff read the
 * served Student's.
 */
final class StatementOfAccountEndpointTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(UserRole $role): User
    {
        return User::create([
            'name' => 'Test '.$role->value.' '.++$this->seq,
            'email' => $role->value.'.'.$this->seq.'.soa@grc.test',
            'password' => 'correct-horse-battery-staple',
            'role' => $role,
            'status' => UserStatus::Active,
        ]);
    }

    private function token(User $user): string
    {
        return $user->createToken('soa-test')->plainTextToken;
    }

    private function student(): StudentProfile
    {
        $program = Program::query()->first()
            ?? Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::query()->first()
            ?? Curriculum::create(['program_id' => $program->id, 'name' => 'BSCS', 'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active]);

        return StudentProfile::create([
            'user_id' => $this->user(UserRole::Student)->id,
            'student_number' => 'SOA-'.++$this->seq,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
    }

    private function term(string $year, string $semester, string $starts): AcademicTerm
    {
        return AcademicTerm::create(['school_year' => $year, 'semester' => $semester, 'status' => AcademicTermStatus::SemesterClosed, 'starts_at' => $starts]);
    }

    /** @return array{0: Enrollment, 1: Assessment} */
    private function assessed(StudentProfile $student, AcademicTerm $term, string $tuition, string $misc, string $discount = '0.00', EnrollmentStatus $status = EnrollmentStatus::Enrolled): array
    {
        $enrollment = Enrollment::create(['student_id' => $student->id, 'academic_term_id' => $term->id, 'status' => $status, 'total_units' => 3, 'submitted_at' => now()]);
        $total = bcsub(bcadd($tuition, $misc, 2), $discount, 2);
        $assessment = Assessment::create(['enrollment_id' => $enrollment->id, 'total_amount' => $total, 'currency' => 'PHP', 'assessed_at' => now()]);
        AssessmentItem::create(['assessment_id' => $assessment->id, 'category' => 'tuition', 'label' => 'Tuition', 'quantity' => '3.0', 'unit_amount' => '200.00', 'amount' => $tuition]);
        AssessmentItem::create(['assessment_id' => $assessment->id, 'category' => 'miscellaneous', 'label' => 'Library', 'quantity' => null, 'unit_amount' => null, 'amount' => $misc]);
        if ($discount !== '0.00') {
            AssessmentItem::create(['assessment_id' => $assessment->id, 'category' => 'scholarship_discount', 'label' => 'Scholarship (40%)', 'quantity' => null, 'unit_amount' => null, 'amount' => '-'.$discount]);
        }

        return [$enrollment, $assessment];
    }

    private function pay(Enrollment $enrollment, string $amount, bool $promissory = false): void
    {
        Payment::create(['enrollment_id' => $enrollment->id, 'confirmed_by' => $this->user(UserRole::AccountingStaff)->id, 'amount' => $amount, 'promissory_note_on_file' => $promissory, 'confirmed_at' => now()]);
    }

    public function test_a_student_reads_their_own_statement_with_a_running_balance_across_terms(): void
    {
        $student = $this->student();
        $first = $this->term('2025-2026', '1st', '2025-08-01');
        $second = $this->term('2025-2026', '2nd', '2026-01-05');
        [$firstEnrollment] = $this->assessed($student, $first, '600.00', '400.00', '0.00', EnrollmentStatus::Enrolled);
        $this->pay($firstEnrollment, '700.00', promissory: true);
        [$secondEnrollment] = $this->assessed($student, $second, '600.00', '400.00', '200.00');
        $this->pay($secondEnrollment, '800.00');

        $response = $this->withToken($this->token($student->user))->getJson('/api/v1/me/statement-of-account');

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.type', 'statement_of_account')
            ->assertJsonPath('data.student.student_number', $student->student_number)
            ->assertJsonPath('data.summary.total_assessed', '1800.00')
            ->assertJsonPath('data.summary.total_paid', '1500.00')
            ->assertJsonPath('data.summary.outstanding_balance', '300.00')
            ->assertJsonCount(2, 'data.terms');
        $one = $response->json('data.terms.0');
        self::assertSame('2025-2026 · 1st', $one['label']);
        self::assertSame('1000.00', $one['assessment_total']);
        self::assertSame('300.00', $one['outstanding']);
        self::assertSame('0.00', $one['prior_balance']);
        self::assertSame('300.00', $one['running_balance']);
        self::assertTrue($one['payments'][0]['promissory_note_on_file']);
        $two = $response->json('data.terms.1');
        self::assertSame('800.00', $two['assessment_total']);
        self::assertSame('0.00', $two['outstanding']);
        self::assertSame('300.00', $two['prior_balance']);
        self::assertSame('300.00', $two['running_balance']);
        self::assertSame('-200.00', $two['scholarship_discount']);
        self::assertSame(['tuition', 'miscellaneous', 'scholarship_discount'], array_column($two['lines'], 'category'));
    }

    public function test_balance_payments_are_allocated_and_advance_payments_are_credits(): void
    {
        $student = $this->student();
        $term = $this->term('2025-2026', '1st', '2025-08-01');
        [$enrollment] = $this->assessed($student, $term, '600.00', '400.00');
        $this->pay($enrollment, '700.00');
        AccountPayment::create(['student_id' => $student->id, 'enrollment_id' => $enrollment->id, 'received_by' => $this->user(UserRole::AccountingStaff)->id, 'amount' => '300.00', 'received_at' => now()]);
        AccountPayment::create(['student_id' => $student->id, 'enrollment_id' => null, 'received_by' => $this->user(UserRole::AccountingStaff)->id, 'amount' => '500.00', 'received_at' => now()]);

        $response = $this->withToken($this->token($student->user))->getJson('/api/v1/me/statement-of-account');

        $response->assertOk()
            ->assertJsonPath('data.terms.0.outstanding', '0.00')
            ->assertJsonCount(2, 'data.terms.0.payments')
            ->assertJsonCount(1, 'data.credits')
            ->assertJsonPath('data.summary.total_paid', '1500.00')
            ->assertJsonPath('data.summary.outstanding_balance', '0.00')
            ->assertJsonPath('data.summary.advance_payment_balance', '500.00');
    }

    public function test_the_term_filter_narrows_the_list_but_keeps_the_balances(): void
    {
        $student = $this->student();
        $first = $this->term('2025-2026', '1st', '2025-08-01');
        $second = $this->term('2025-2026', '2nd', '2026-01-05');
        $this->assessed($student, $first, '600.00', '400.00');
        $this->assessed($student, $second, '600.00', '400.00');

        $response = $this->withToken($this->token($student->user))
            ->getJson("/api/v1/me/statement-of-account?academic_term_id={$second->id}");

        $response->assertOk()->assertJsonCount(1, 'data.terms')
            ->assertJsonPath('data.terms.0.academic_term_id', $second->id)
            // The first term's unpaid 1,000.00 is still carried in.
            ->assertJsonPath('data.terms.0.prior_balance', '1000.00')
            ->assertJsonPath('data.terms.0.running_balance', '2000.00')
            ->assertJsonPath('data.summary.outstanding_balance', '2000.00');
    }

    public function test_rejected_and_cancelled_enrollments_are_left_off(): void
    {
        $student = $this->student();
        $term = $this->term('2025-2026', '1st', '2025-08-01');
        $this->assessed($student, $term, '600.00', '400.00', '0.00', EnrollmentStatus::Cancelled);

        $this->withToken($this->token($student->user))->getJson('/api/v1/me/statement-of-account')
            ->assertOk()->assertJsonCount(0, 'data.terms')->assertJsonPath('data.summary.total_assessed', '0.00');
    }

    public function test_accounting_staff_read_a_students_statement_and_the_pdf(): void
    {
        $student = $this->student();
        $term = $this->term('2025-2026', '1st', '2025-08-01');
        $this->assessed($student, $term, '600.00', '400.00');
        $token = $this->token($this->user(UserRole::AccountingStaff));

        $this->withToken($token)->getJson("/api/v1/students/{$student->id}/statement-of-account")
            ->assertOk()->assertJsonPath('data.student.student_number', $student->student_number);

        $pdf = $this->withToken($token)->get("/api/v1/students/{$student->id}/statement-of-account/pdf");
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_a_student_can_download_their_own_pdf(): void
    {
        $student = $this->student();
        $token = $this->token($student->user);

        $response = $this->withToken($token)->get('/api/v1/me/statement-of-account/pdf');

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        self::assertStringContainsString('SOA-', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_the_pdf_uses_a_font_that_can_print_the_peso_sign(): void
    {
        // The base-14 PDF fonts (Helvetica/Arial) have no glyph for the Peso sign and DomPDF prints
        // "?" in its place; DejaVu Sans (bundled with DomPDF) does. Regression guard for that swap.
        $student = $this->student();
        $term = $this->term('2025-2026', '1st', '2025-08-01');
        $this->assessed($student, $term, '600.00', '400.00');
        $statement = app(BuildStatementOfAccount::class)->execute($student->load(['user', 'program']), null);

        $html = view('pdf.statement-of-account', ['statement' => $statement])->render();

        $this->assertStringContainsString("font-family: 'DejaVu Sans', sans-serif;", $html);
    }

    public function test_a_student_cannot_read_another_students_statement(): void
    {
        $mine = $this->student();
        $other = $this->student();

        $this->withToken($this->token($mine->user))->getJson("/api/v1/students/{$other->id}/statement-of-account")->assertForbidden();
    }

    public function test_other_roles_are_forbidden(): void
    {
        $student = $this->student();

        foreach ([UserRole::RegistrarHead, UserRole::RegistrarStaff, UserRole::Dean, UserRole::ProgramChair, UserRole::AdmissionStaff] as $role) {
            $this->withToken($this->token($this->user($role)))->getJson("/api/v1/students/{$student->id}/statement-of-account")->assertForbidden();
            $this->withToken($this->token($this->user($role)))->getJson('/api/v1/me/statement-of-account')->assertForbidden();
            $this->flushHeaders();
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_the_term_must_exist(): void
    {
        $student = $this->student();

        $this->withToken($this->token($student->user))->getJson('/api/v1/me/statement-of-account?academic_term_id=99999')->assertUnprocessable();
    }
}
