<?php

namespace Tests\Feature\Actions\Enrollment;

use App\Actions\Enrollment\ConfirmFreshmanPayment;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Enrollment\FreshmanPaymentResult;
use App\Domain\Enrollment\QueueTicketStatus;
use App\Domain\Identity\StudentType;
use App\Domain\Identity\UserRole;
use App\Domain\Notifications\NotificationType;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\AccountPayment;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\EnrollmentDocument;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsBlockSections;
use Tests\Support\BuildsFreshmen;
use Tests\TestCase;

/**
 * The Cashier confirms a freshman's first payment (ADR 0043, amended 2026-10-04): the
 * payment is taken and held as credit, and the freshman is submitted to the first block
 * with open seats. The enrollment is NOT complete until the Registrar approves it, and only
 * then is the enrolled email sent. No promissory note, at least PHP 1,000.
 */
final class ConfirmFreshmanPaymentTest extends TestCase
{
    use BuildsBlockSections;
    use BuildsFreshmen;
    use RefreshDatabase;

    public function test_a_freshman_is_submitted_to_the_first_block_with_open_seats_and_waits_for_the_registrar(): void
    {
        [$profile, $term, $plan] = $this->world();
        $first = $this->blockSection($term, $plan, 'IT101', 'ITP1');
        $second = $this->blockSection($term, $plan, 'IT102', 'ITP1B');
        $this->issueFreshmanTicket($profile);

        $result = $this->confirm($profile, '1000.00');

        self::assertSame(FreshmanPaymentResult::AWAITING_REGISTRAR, $result->outcome);
        self::assertTrue($result->created);
        self::assertSame('IT101', $result->blockCode);
        $enrollment = $result->enrollment;
        self::assertNotNull($enrollment);
        // Paid, but not enrolled: the Registrar's approval is what enrolls them.
        self::assertSame(EnrollmentStatus::PendingRegistrarApproval, $enrollment->status);
        self::assertNotNull($enrollment->payment_confirmed_at);
        self::assertNull($enrollment->enrolled_at);
        // The seat in IT101 is taken, IT102 is untouched.
        self::assertSame(1, $first->fresh()->enrolled_count);
        self::assertSame(0, $second->fresh()->enrolled_count);
        self::assertSame(
            [EnrollmentSubjectStatus::Selected],
            $enrollment->enrollmentSubjects->pluck('status')->unique()->values()->all(),
        );
    }

    public function test_it_holds_the_payment_serves_the_ticket_and_issues_no_cor_before_the_registrar_approves(): void
    {
        [$profile, $term, $plan] = $this->world();
        $this->blockSection($term, $plan, 'IT101', 'ITP1');
        $issued = $this->issueFreshmanTicket($profile);

        $result = $this->confirm($profile, '1000.00', 'OR-7');

        $enrollment = $result->enrollment;
        self::assertNotNull($enrollment);
        // The money is held as credit with no enrollment yet, so the Registrar's approval can apply it.
        $credit = AccountPayment::query()->sole();
        self::assertSame('1000.00', $credit->amount);
        self::assertNull($credit->enrollment_id);
        // No Payment row and no COR until the Registrar approves.
        self::assertSame(0, Payment::query()->count());
        self::assertSame(0, EnrollmentDocument::query()->count());
        // The ticket is served; it is not linked to the enrollment (that belongs to the approval).
        self::assertSame(QueueTicketStatus::Served, $issued->ticket->fresh()->status);
        self::assertNotNull($profile->fresh()->freshman_payment_confirmed_at);
        $audit = AuditLog::query()->where('action', AuditAction::FRESHMAN_PAYMENT_CONFIRMED)->sole();
        self::assertSame('OR-7', $audit->after_values['external_reference']);
        self::assertSame(FreshmanPaymentResult::AWAITING_REGISTRAR, $audit->after_values['outcome']);
        // The enrolled notification is for the approval, not for this payment.
        self::assertSame(0, Notification::query()
            ->where('user_id', $profile->user_id)
            ->where('type', NotificationType::EnrollmentPaymentConfirmed)
            ->count());
        self::assertSame(1, Notification::query()
            ->where('user_id', $profile->user_id)
            ->where('type', NotificationType::FreshmanAwaitingRegistrarApproval)
            ->count());
    }

    public function test_the_registrar_is_told_a_freshman_who_paid_is_waiting_for_approval(): void
    {
        [$profile, $term, $plan] = $this->world();
        $this->blockSection($term, $plan, 'IT101', 'ITP1');
        $registrar = $this->staffUser(UserRole::RegistrarStaff, 'registrar.staff@grc.test');
        $this->issueFreshmanTicket($profile);

        $this->confirm($profile, '1000.00');

        self::assertSame(1, Notification::query()
            ->where('user_id', $registrar->id)
            ->where('type', NotificationType::FreshmanAwaitingRegistrarApproval)
            ->count());
        // Nothing has been approved by the Registrar yet.
        self::assertSame(0, AuditLog::query()->where('action', AuditAction::ENROLLMENT_REGISTRAR_APPROVED)->count());
    }

    public function test_the_next_block_is_used_when_the_first_is_full(): void
    {
        [$profile, $term, $plan] = $this->world();
        $this->fullBlock($term, $plan, 'IT101', 'ITP1');
        $this->blockSection($term, $plan, 'IT102', 'ITP1B');
        $this->issueFreshmanTicket($profile);

        $result = $this->confirm($profile, '1000.00');

        self::assertSame(FreshmanPaymentResult::AWAITING_REGISTRAR, $result->outcome);
        self::assertSame('IT102', $result->blockCode);
    }

    public function test_blocks_are_taken_in_block_number_order_not_creation_order(): void
    {
        [$profile, $term, $plan] = $this->world();
        $this->blockSection($term, $plan, 'IT110', 'ITP10');
        $this->blockSection($term, $plan, 'IT102', 'ITP2');
        $this->fullBlock($term, $plan, 'IT101', 'ITP1');
        $this->issueFreshmanTicket($profile);

        self::assertSame('IT102', $this->confirm($profile, '1000.00')->blockCode);
    }

    public function test_a_block_with_one_full_subject_counts_as_full(): void
    {
        [$profile, $term, $plan] = $this->world();
        $this->blockSection($term, $plan, 'IT101', 'ITP1');
        $this->fullBlock($term, $plan, 'IT101', 'ITP1L');
        $this->blockSection($term, $plan, 'IT102', 'ITP2');
        $this->issueFreshmanTicket($profile);

        self::assertSame('IT102', $this->confirm($profile, '1000.00')->blockCode);
    }

    public function test_a_closed_enrollment_window_does_not_stop_a_freshman_who_pays(): void
    {
        [$profile, $term, $plan] = $this->world(windowOpen: false);
        $this->blockSection($term, $plan, 'IT101', 'ITP1');
        $this->issueFreshmanTicket($profile);

        self::assertSame(FreshmanPaymentResult::AWAITING_REGISTRAR, $this->confirm($profile, '1000.00')->outcome);
    }

    public function test_a_payment_below_the_minimum_is_refused_and_nothing_is_written(): void
    {
        [$profile, $term, $plan] = $this->world();
        $section = $this->blockSection($term, $plan, 'IT101', 'ITP1');
        $this->issueFreshmanTicket($profile);

        try {
            $this->confirm($profile, '999.99');
            self::fail('A payment below PHP 1,000 must be refused.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('amount', $exception->errors());
        }

        self::assertSame(0, Payment::query()->count());
        self::assertSame(0, AccountPayment::query()->count());
        self::assertSame(0, Enrollment::query()->count());
        self::assertSame(0, $section->fresh()->enrolled_count);
        self::assertNull($profile->fresh()->freshman_payment_confirmed_at);
    }

    public function test_the_minimum_payment_is_accepted_without_a_promissory_note_and_waits_for_the_registrar(): void
    {
        [$profile, $term, $plan] = $this->world();
        $this->blockSection($term, $plan, 'IT101', 'ITP1');
        $this->issueFreshmanTicket($profile);

        $result = $this->confirm($profile, '1000.00');

        $enrollment = $result->enrollment;
        self::assertNotNull($enrollment);
        self::assertSame(EnrollmentStatus::PendingRegistrarApproval, $enrollment->status);
        // No promissory note is asked for, and the held amount is the minimum, not a partial-balance problem.
        self::assertSame('1000.00', AccountPayment::query()->sole()->amount);
        self::assertSame(0, EnrollmentDocument::query()->count());
    }

    public function test_when_every_block_is_full_the_payment_is_held_as_credit(): void
    {
        [$profile, $term, $plan] = $this->world();
        $this->fullBlock($term, $plan, 'IT101', 'ITP1');
        $this->fullBlock($term, $plan, 'IT102', 'ITP2');
        $issued = $this->issueFreshmanTicket($profile);

        $result = $this->confirm($profile, '1500.00');

        self::assertSame(FreshmanPaymentResult::AWAITING_SECTION, $result->outcome);
        self::assertNull($result->enrollment);
        self::assertNull($result->blockCode);
        self::assertSame(0, Enrollment::query()->count());
        $credit = AccountPayment::query()->sole();
        self::assertNull($credit->enrollment_id);
        self::assertSame($profile->id, $credit->student_id);
        self::assertSame('1500.00', $credit->amount);
        self::assertNotNull($profile->fresh()->freshman_payment_confirmed_at);
        // Their turn at the Cashier is done.
        self::assertSame(QueueTicketStatus::Served, $issued->ticket->fresh()->status);
        self::assertSame(1, Notification::query()
            ->where('user_id', $profile->user_id)
            ->where('type', NotificationType::FreshmanAwaitingSection)
            ->count());
    }

    public function test_an_irregular_freshman_pays_into_credit_and_is_not_given_a_block(): void
    {
        [$profile, $term, $plan] = $this->world(category: 'irregular');
        $this->blockSection($term, $plan, 'IT101', 'ITP1');
        $this->issueFreshmanTicket($profile);

        $result = $this->confirm($profile, '1000.00');

        self::assertSame(FreshmanPaymentResult::AWAITING_SECTION, $result->outcome);
        self::assertSame(0, Enrollment::query()->count());
        self::assertSame('1000.00', AccountPayment::query()->sole()->amount);
    }

    public function test_an_irregular_freshman_is_not_told_that_every_section_is_full(): void
    {
        [$profile, $term, $plan] = $this->world(category: 'irregular');
        $this->blockSection($term, $plan, 'IT101', 'ITP1');
        $this->issueFreshmanTicket($profile);

        $this->confirm($profile, '1000.00');

        // Sections are not what an irregular freshman waits for: they choose their own subjects.
        $message = Notification::query()
            ->where('user_id', $profile->user_id)
            ->where('type', NotificationType::FreshmanAwaitingSection)
            ->sole()
            ->message;
        self::assertStringNotContainsString('full', $message);
        self::assertStringContainsString('choose your subjects', $message);
    }

    public function test_a_repeat_reports_what_was_recorded_the_first_time_not_the_new_amount(): void
    {
        [$profile, $term, $plan] = $this->world();
        $this->fullBlock($term, $plan, 'IT101', 'ITP1');
        $this->issueFreshmanTicket($profile);

        $this->confirm($profile, '1000.00');
        $second = $this->confirm($profile, '1500.00');

        self::assertFalse($second->created);
        self::assertSame('1000.00', $second->amount);
        self::assertSame(1, AccountPayment::query()->count());
    }

    public function test_confirming_twice_changes_nothing_the_second_time(): void
    {
        [$profile, $term, $plan] = $this->world();
        $section = $this->blockSection($term, $plan, 'IT101', 'ITP1');
        $this->issueFreshmanTicket($profile);

        $first = $this->confirm($profile, '1000.00');
        $second = $this->confirm($profile, '1000.00');

        self::assertTrue($first->created);
        self::assertFalse($second->created);
        self::assertSame($first->outcome, $second->outcome);
        self::assertSame($first->enrollment?->id, $second->enrollment?->id);
        self::assertSame(1, AccountPayment::query()->count());
        self::assertSame(1, Enrollment::query()->count());
        self::assertSame(1, $section->fresh()->enrolled_count);
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::FRESHMAN_PAYMENT_CONFIRMED)->count());
    }

    public function test_confirming_a_held_payment_twice_does_not_record_the_money_twice(): void
    {
        [$profile, $term, $plan] = $this->world();
        $this->fullBlock($term, $plan, 'IT101', 'ITP1');
        $this->issueFreshmanTicket($profile);

        $this->confirm($profile, '1000.00');
        $second = $this->confirm($profile, '1000.00');

        self::assertFalse($second->created);
        self::assertSame(1, AccountPayment::query()->count());
    }

    public function test_only_a_student_who_pays_first_can_use_the_first_payment_flow(): void
    {
        [, $term, $plan] = $this->world();
        $this->blockSection($term, $plan, 'IT101', 'ITP1');
        // A Returnee follows the ordinary flow; a Transferee now pays first like a freshman (ADR 0043, amended 2026-10-05).
        $returnee = $this->freshmanProfile('2026-10-00002', StudentType::Returnee);

        $this->expectException(ValidationException::class);

        $this->confirm($returnee, '1000.00');
    }

    /**
     * @return array{0: StudentProfile, 1: AcademicTerm, 2: AcademicTermSectionPlan}
     */
    private function world(bool $windowOpen = true, string $category = 'regular'): array
    {
        $profile = $this->freshmanProfile('2026-10-00001', category: $category);
        $term = $this->ongoingTerm($windowOpen);
        $plan = $this->yearOnePlan($term, $profile->curriculum);

        return [$profile, $term, $plan];
    }

    private function confirm(StudentProfile $profile, string $amount, ?string $reference = null): FreshmanPaymentResult
    {
        return $this->app->make(ConfirmFreshmanPayment::class)->execute(
            $profile->fresh(),
            array_filter(['amount' => $amount, 'external_reference' => $reference]),
            $this->cashier(),
            new AuditRequestContext('test-freshman-payment', null),
        );
    }

    private function cashier(): User
    {
        return $this->staffUser(UserRole::AccountingStaff, 'cashier@grc.test');
    }
}
