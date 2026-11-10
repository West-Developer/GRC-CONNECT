<?php

namespace Tests\Feature\Actions\Enrollment;

use App\Actions\Enrollment\ConfirmFreshmanPayment;
use App\Actions\Enrollment\SubmitEnrollment;
use App\Actions\Enrollment\TransitionEnrollment;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Enrollment\FreshmanPaymentResult;
use App\Domain\Identity\StudentType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Notifications\NotificationType;
use App\Models\AcademicTerm;
use App\Models\AccountPayment;
use App\Models\Enrollment;
use App\Models\EnrollmentDocument;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\QueueTicket;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsBlockSections;
use Tests\Support\BuildsFreshmen;
use Tests\TestCase;

/**
 * The all-sections-full path (ADR 0043): a freshman paid while every block was full,
 * a section opened later, they enrolled in it and Registrar Staff approved. That
 * approval completes the enrollment from the payment already held — no queue, no
 * second payment.
 */
final class SettleEnrollmentFromAdvanceCreditTest extends TestCase
{
    use BuildsBlockSections;
    use BuildsFreshmen;
    use RefreshDatabase;

    public function test_approving_a_waiting_freshmans_enrollment_completes_it_from_the_held_payment(): void
    {
        [$profile, $term, $cashier] = $this->waitingFreshman('1200.00');
        $opened = $this->blockSection($term, $this->yearOnePlan($term, $profile->curriculum), 'IT103', 'ITP3');

        $enrollment = $this->submit($profile, $term, 'IT103');
        $approved = $this->approve($enrollment);

        self::assertSame(EnrollmentStatus::Enrolled, $approved->status);
        self::assertNotNull($approved->payment_confirmed_at);
        self::assertSame(
            [EnrollmentSubjectStatus::Enrolled],
            $approved->enrollmentSubjects->pluck('status')->unique()->values()->all(),
        );
        self::assertSame(1, $opened->fresh()->enrolled_count);
        // One Payment row (the idempotency anchor) that adds nothing on top of the held money,
        // which now belongs to this enrollment.
        $payment = Payment::query()->where('enrollment_id', $enrollment->id)->sole();
        self::assertSame('0.00', $payment->amount);
        self::assertSame($cashier->id, $payment->confirmed_by);
        self::assertSame($enrollment->id, AccountPayment::query()->sole()->enrollment_id);
        $document = EnrollmentDocument::query()->where('enrollment_id', $enrollment->id)->sole();
        self::assertSame('1200.00', $document->snapshot['fees']['amount_paid']);
    }

    public function test_the_student_is_told_the_earlier_payment_was_used_not_to_go_and_pay(): void
    {
        [$profile, $term] = $this->waitingFreshman('1000.00');
        $this->blockSection($term, $this->yearOnePlan($term, $profile->curriculum), 'IT103', 'ITP3');

        $this->approve($this->submit($profile, $term, 'IT103'));

        $message = Notification::query()
            ->where('user_id', $profile->user_id)
            ->where('type', NotificationType::EnrollmentRegistrarApproved)
            ->latest('id')
            ->value('message');
        self::assertStringContainsString('payment', strtolower((string) $message));
        self::assertStringNotContainsString('Visit the Cashier', (string) $message);
    }

    public function test_no_queue_ticket_is_needed_or_created(): void
    {
        [$profile, $term] = $this->waitingFreshman('1000.00');
        $this->blockSection($term, $this->yearOnePlan($term, $profile->curriculum), 'IT103', 'ITP3');
        $ticketsBefore = QueueTicket::query()->count();

        $this->approve($this->submit($profile, $term, 'IT103'));

        self::assertSame($ticketsBefore, QueueTicket::query()->count());
    }

    public function test_a_freshman_whose_held_payment_is_below_the_minimum_still_goes_to_the_cashier(): void
    {
        [$profile, $term] = $this->waitingFreshman('1000.00');
        // Something took the credit below PHP 1,000 (for example a correction).
        AccountPayment::query()->update(['amount' => '500.00']);
        $this->blockSection($term, $this->yearOnePlan($term, $profile->curriculum), 'IT103', 'ITP3');

        $approved = $this->approve($this->submit($profile, $term, 'IT103'));

        self::assertSame(EnrollmentStatus::PendingPayment, $approved->status);
    }

    public function test_a_student_who_is_not_a_freshman_is_unaffected_by_other_peoples_credit(): void
    {
        [$profile, $term] = $this->waitingFreshman('1000.00');
        // A student who does not pay first (a Returnee): a Transferee now pays first too (ADR 0043, amended 2026-10-05).
        $transferee = $this->freshmanProfile('2026-10-00009', StudentType::Returnee);
        $this->blockSection($term, $this->yearOnePlan($term, $profile->curriculum), 'IT103', 'ITP3');
        // Held money that is NOT this student's must never be applied to them.
        AccountPayment::create([
            'student_id' => $profile->id, 'enrollment_id' => null,
            'received_by' => $this->staffUser(UserRole::AccountingStaff, 'cashier@grc.test')->id,
            'amount' => '5000.00', 'received_at' => now(),
        ]);

        $approved = $this->approve($this->submit($transferee, $term, 'IT103'));

        self::assertSame(EnrollmentStatus::PendingPayment, $approved->status);
    }

    public function test_a_freshman_with_no_held_payment_goes_to_the_cashier_as_usual(): void
    {
        $profile = $this->freshmanProfile('2026-10-00005');
        $term = $this->ongoingTerm();
        $this->blockSection($term, $this->yearOnePlan($term, $profile->curriculum), 'IT101', 'ITP1');

        $approved = $this->approve($this->submit($profile, $term, 'IT101'));

        self::assertSame(EnrollmentStatus::PendingPayment, $approved->status);
        self::assertSame(0, Payment::query()->count());
    }

    public function test_settling_is_not_repeated_for_the_same_enrollment(): void
    {
        [$profile, $term] = $this->waitingFreshman('1000.00');
        $this->blockSection($term, $this->yearOnePlan($term, $profile->curriculum), 'IT103', 'ITP3');
        $enrollment = $this->submit($profile, $term, 'IT103');

        $this->approve($enrollment);

        $this->expectException(ValidationException::class);
        $this->approve($enrollment);
    }

    public function test_the_profile_says_a_payment_is_held_until_the_approval_applies_it(): void
    {
        [$profile, $term] = $this->waitingFreshman('1000.00');
        $this->blockSection($term, $this->yearOnePlan($term, $profile->curriculum), 'IT103', 'ITP3');

        // Paid, nothing applied yet — the student's own screens must not send them to a queue.
        self::assertTrue($this->ownProfile($profile)['advance_payment_held']);

        $enrollment = $this->submit($profile, $term, 'IT103');
        self::assertTrue($this->ownProfile($profile)['advance_payment_held']);

        $this->approve($enrollment);
        self::assertFalse($this->ownProfile($profile)['advance_payment_held']);
    }

    public function test_a_freshman_who_has_not_paid_holds_nothing_and_a_payment_stays_held_until_the_registrar_approves(): void
    {
        $unpaid = $this->freshmanProfile('2026-10-00001');
        $term = $this->ongoingTerm();
        $this->blockSection($term, $this->yearOnePlan($term, $unpaid->curriculum), 'IT101', 'ITP1');
        self::assertFalse($this->ownProfile($unpaid)['advance_payment_held']);

        $this->issueFreshmanTicket($unpaid);
        $result = $this->app->make(ConfirmFreshmanPayment::class)->execute(
            $unpaid->fresh(),
            ['amount' => '1000.00'],
            $this->staffUser(UserRole::AccountingStaff, 'cashier@grc.test'),
            new AuditRequestContext('test', null),
        );
        // A block had seats, but the money is held until the Registrar approves the enrollment.
        self::assertSame(EnrollmentStatus::PendingRegistrarApproval, $result->enrollment?->status);
        self::assertTrue($this->ownProfile($unpaid)['advance_payment_held']);
    }

    public function test_money_held_for_someone_who_is_not_a_freshman_does_not_count(): void
    {
        $transferee = $this->freshmanProfile('2026-10-00009', StudentType::Transferee);
        AccountPayment::create([
            'student_id' => $transferee->id, 'enrollment_id' => null,
            'received_by' => $this->staffUser(UserRole::AccountingStaff, 'cashier@grc.test')->id,
            'amount' => '5000.00', 'received_at' => now(),
        ]);

        self::assertFalse($this->ownProfile($transferee)['advance_payment_held']);
    }

    public function test_the_admission_directory_carries_the_same_flag_without_a_query_per_row(): void
    {
        $this->waitingFreshman('1000.00');
        $this->freshmanProfile('2026-10-00002');
        $token = $this->staffUser(UserRole::AdmissionStaff, 'admission@grc.test')->createToken('directory')->plainTextToken;
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        DB::enableQueryLog();
        $response = $this->withToken($token)->getJson('/api/v1/student-profiles?search=2026-10')->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $rows = collect($response->json('data'))->keyBy('student_number');
        self::assertTrue($rows['2026-10-00001']['advance_payment_held']);
        self::assertFalse($rows['2026-10-00002']['advance_payment_held']);
        // The credit is summed inside the one list query; a row never triggers its own lookup.
        $perRowCreditQueries = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from `account_payments`') && ! str_contains($sql, 'held_advance_payment_sum'));
        self::assertSame([], array_values($perRowCreditQueries));
    }

    /**
     * What the student's own screens are given: the profile as the API serves it to them.
     *
     * @return array<string, mixed>
     */
    private function ownProfile(StudentProfile $profile): array
    {
        $user = $profile->user->fresh();
        $user->forceFill(['status' => UserStatus::Active, 'last_otp_verified_at' => now()])->save();
        $token = $user->createToken('held-check')->plainTextToken;
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson('/api/v1/student-profile')->assertOk()->json('data');
    }

    /**
     * @return array{0: StudentProfile, 1: AcademicTerm, 2: User}
     */
    private function waitingFreshman(string $amount): array
    {
        $profile = $this->freshmanProfile('2026-10-00001');
        $term = $this->ongoingTerm();
        $plan = $this->yearOnePlan($term, $profile->curriculum);
        // Every block is full at the Cashier, so the payment is held.
        $this->fullBlock($term, $plan, 'IT101', 'ITP1');
        $this->issueFreshmanTicket($profile);
        $cashier = $this->staffUser(UserRole::AccountingStaff, 'cashier@grc.test');
        $this->staffUser(UserRole::RegistrarStaff, 'registrar.staff@grc.test');

        $result = $this->app->make(ConfirmFreshmanPayment::class)->execute(
            $profile->fresh(),
            ['amount' => $amount],
            $cashier,
            new AuditRequestContext('test', null),
        );
        self::assertSame(FreshmanPaymentResult::AWAITING_SECTION, $result->outcome);

        return [$profile->fresh(), $term, $cashier];
    }

    private function submit(StudentProfile $profile, AcademicTerm $term, string $blockCode): Enrollment
    {
        $sectionIds = Section::query()->where('academic_term_id', $term->id)->where('section_code', $blockCode)->pluck('id')->all();
        $profile->user->forceFill(['status' => UserStatus::Active])->save();

        return $this->app->make(SubmitEnrollment::class)->execute(
            $profile,
            $term,
            array_map('intval', $sectionIds),
            $profile->user,
            new AuditRequestContext('test-submit', null),
            $blockCode,
        );
    }

    private function approve(Enrollment $enrollment): Enrollment
    {
        return $this->app->make(TransitionEnrollment::class)->execute(
            $enrollment,
            'registrar_approve',
            $this->staffUser(UserRole::RegistrarStaff, 'registrar.staff@grc.test'),
            null,
            new AuditRequestContext('test-approve', null),
        );
    }
}
