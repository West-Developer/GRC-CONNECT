<?php

namespace App\Actions\Enrollment;

use App\Actions\Billing\RecordAccountPayment;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\EnrollmentBlock;
use App\Domain\Enrollment\EnrollmentCategory;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\FreshmanPaymentResult;
use App\Domain\Enrollment\QueueTicketStatus;
use App\Domain\Identity\StudentType;
use App\Domain\Identity\UserRole;
use App\Domain\Notifications\NotificationType;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use App\Models\AccountPayment;
use App\Models\Enrollment;
use App\Models\Notification;
use App\Models\QueueTicket;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Notifications\NotificationRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Cashier confirms a freshman's first payment (ADR 0043). A freshman reaches the
 * Cashier before any account or enrollment exists, so one transaction does it all.
 * The payment is taken here; the enrollment is NOT completed here (owner decision,
 * 2026-10-04): the Registrar approves it first.
 *
 * - **The money is always held as credit first** (an `account_payments` row with no
 *   enrollment), before any enrollment exists, so it can never be allocated to one by
 *   mistake. The ticket is served and the profile is marked paid.
 * - **A block has open seats:** the freshman is submitted to the first one, in block-
 *   number order (`IT101`, else `IT102`, ...), as `pending_registrar_approval` and
 *   `payment_confirmed_at` is stamped. They appear in Enrollment Approvals. Registrar
 *   approval (`registrar_approve`) then completes the enrollment from the held credit
 *   (`SettleEnrollmentFromAdvanceCredit`): Enrolled, the COR, and the one enrolled email.
 * - **Every block is full, or the freshman is not enrolled by block (an irregular one):**
 *   the payment stays held and the freshman is told a section will be announced. The
 *   later enrollment is completed from that credit the same way.
 *
 * Idempotent: `student_profiles.freshman_payment_confirmed_at` is checked first, under the
 * profile's row lock, so a repeat returns what was done the first time and writes nothing.
 * The rule is at least the configured minimum and no promissory note (`FreshmanPaymentRules`).
 * The enrollment window is deliberately not consulted: the Cashier is the gate. The account
 * setup email and the enrollment confirmation email are sent by the caller after commit.
 *
 * ADR 0046: `payment_type` `full` collects the discounted estimate and freezes the quoted
 * percentage on the profile.
 */
final readonly class ConfirmFreshmanPayment
{
    public function __construct(
        private BuildEnrollmentBlockPool $blockPool,
        private PreviewFreshmanPayment $previewFreshmanPayment,
        private SubmitEnrollment $submitEnrollment,
        private RecordAccountPayment $recordAccountPayment,
        private AuditRecorder $auditRecorder,
        private NotificationRecorder $notificationRecorder,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  `amount` (required) and `external_reference`
     */
    public function execute(StudentProfile $profile, array $validated, User $cashier, AuditRequestContext $context): FreshmanPaymentResult
    {
        $isFullPayment = ($validated['payment_type'] ?? null) === 'full';
        $amount = $isFullPayment
            ? FreshmanPaymentRules::normalizeFullAmount($validated['amount'] ?? null)
            : FreshmanPaymentRules::normalizeAmount($validated['amount'] ?? null);

        return DB::transaction(function () use ($profile, $validated, $cashier, $context, $amount, $isFullPayment): FreshmanPaymentResult {
            $locked = StudentProfile::query()->whereKey($profile->id)->lockForUpdate()->with('user')->firstOrFail();

            if (! $locked->student_type instanceof StudentType || ! $locked->student_type->isPayFirst()) {
                throw ValidationException::withMessages([
                    'student' => 'Only a freshman\'s or transferee\'s first payment is confirmed this way.',
                ]);
            }

            if ($locked->freshman_payment_confirmed_at !== null) {
                return $this->existingResult($locked, $amount);
            }

            // ADR 0046: a full payment is re-quoted under the profile lock. The amount the Cashier was
            // shown must still be what a full payment collects, and the percentage that applied is
            // frozen on the profile for the Registrar's approval to use.
            $frozenPercentage = null;

            if ($isFullPayment) {
                $quote = $this->previewFreshmanPayment->execute($locked)->fullPayment;

                if ($quote === null || $quote->netTotal === null) {
                    throw ValidationException::withMessages([
                        'payment_type' => 'A full payment cannot be quoted yet. Take an installment instead.',
                    ]);
                }

                // The quote's amounts are built from bcmath results, so they are numeric strings.
                /** @var numeric-string $netTotal */
                $netTotal = $quote->netTotal;

                if (bccomp($amount, $netTotal, 2) !== 0) {
                    throw ValidationException::withMessages([
                        'amount' => 'The amount due changed. Review the new amount and confirm again.',
                    ]);
                }

                $frozenPercentage = $quote->applies() ? $quote->percentage : null;
            }

            // The money is recorded before the enrollment exists, so it cannot be allocated to it.
            $this->holdAsCredit($locked, $amount, $cashier, $context);

            // A transferee chose their own subjects before paying (the payment hold); a freshman is
            // given the first block with seats.
            $isTransferee = $locked->student_type === StudentType::Transferee;
            if ($isTransferee) {
                [$enrollment, $blockCode] = $this->releaseHoldToRegistrar($locked);
            } else {
                $term = AcademicTerm::query()->where('status', AcademicTermStatus::SemesterOngoing)->first();
                [$enrollment, $blockCode] = $term === null ? [null, null] : $this->submitToFirstOpenBlock($locked, $term, $context);
            }

            if ($enrollment !== null) {
                // The Cashier's part is done; the Registrar's approval is what enrolls them.
                $enrollment->update(['payment_confirmed_at' => now()]);
            }

            $locked->forceFill([
                'freshman_payment_confirmed_at' => now(),
                'full_payment_discount_percentage' => $frozenPercentage,
            ])->save();
            $this->serveTicket($locked);

            $outcome = $enrollment !== null ? FreshmanPaymentResult::AWAITING_REGISTRAR : FreshmanPaymentResult::AWAITING_SECTION;

            $this->auditRecorder->record(
                $cashier,
                AuditAction::FRESHMAN_PAYMENT_CONFIRMED,
                AuditableType::STUDENT_PROFILE,
                $locked->id,
                ['freshman_payment_confirmed_at' => null],
                [
                    'outcome' => $outcome,
                    'amount' => $amount,
                    'external_reference' => $validated['external_reference'] ?? null,
                    'student_type' => $locked->student_type?->value,
                    'block_code' => $blockCode,
                    'enrollment_id' => $enrollment?->id,
                    'payment_type' => $isFullPayment ? 'full' : 'installment',
                    'full_payment_discount_percentage' => $frozenPercentage,
                ],
                null,
                $context,
            );

            if ($enrollment === null) {
                Notification::create([
                    'user_id' => $locked->user_id,
                    'type' => NotificationType::FreshmanAwaitingSection,
                    'message' => $isTransferee
                        ? $this->transfereeHeldPaymentMessage($amount)
                        : $this->heldPaymentMessage($locked, $amount),
                ]);
            } else {
                Notification::create([
                    'user_id' => $locked->user_id,
                    'type' => NotificationType::FreshmanAwaitingRegistrarApproval,
                    'message' => $this->awaitingRegistrarMessage($amount, $isTransferee ? null : $blockCode),
                ]);
                $this->notificationRecorder->recordManyForRoles(
                    [UserRole::RegistrarStaff, UserRole::RegistrarHead],
                    NotificationType::FreshmanAwaitingRegistrarApproval,
                    $isTransferee
                        ? sprintf('Transferee %s paid at the Cashier and is waiting for your approval.', $locked->student_number)
                        : sprintf('Freshman %s paid at the Cashier and is waiting for your approval (block %s).', $locked->student_number, $blockCode),
                );
            }

            return new FreshmanPaymentResult($outcome, true, $locked->refresh(), $enrollment, $blockCode, $amount);
        });
    }

    /**
     * The first block, in block-number order, that can be chosen and still has seats for
     * every subject. A block whose last seat goes to someone else between the check and
     * the reservation is skipped for the next one. A freshman who is not enrolled by block
     * (an irregular one) is never given a block. The enrollment is submitted and waits for
     * the Registrar; `SubmitEnrollment` routes a block submission straight to Registrar Staff.
     *
     * @return array{0: ?Enrollment, 1: ?string}
     */
    private function submitToFirstOpenBlock(StudentProfile $profile, AcademicTerm $term, AuditRequestContext $context): array
    {
        if ($profile->enrollment_category === EnrollmentCategory::Irregular->value) {
            return [null, null];
        }

        $blocks = array_values(array_filter(
            $this->blockPool->execute($profile, $term, ignoreEnrollmentWindow: true),
            fn (EnrollmentBlock $block): bool => $block->isSelectable && $block->seatsRemaining > 0,
        ));
        usort($blocks, fn (EnrollmentBlock $a, EnrollmentBlock $b): int => strnatcmp($a->blockCode, $b->blockCode));

        foreach ($blocks as $block) {
            try {
                $enrollment = $this->submitEnrollment->execute(
                    $profile,
                    $term,
                    array_map(fn ($section): int => $section->id, $block->sections),
                    $profile->user,
                    $context,
                    $block->blockCode,
                    notify: false,
                );
            } catch (ValidationException) {
                continue;
            }

            return [$enrollment, $block->blockCode];
        }

        return [null, null];
    }

    /**
     * A transferee's chosen subjects wait at `awaiting_first_payment`; the Cashier's payment is
     * what releases them to the Registrar. The enrollment keeps the seats it reserved when it was
     * submitted. A transferee with no such enrollment yet (they paid before choosing) has nothing
     * to release: the money stays held and their later submission goes straight to the Registrar
     * (`EnrollmentRouting`).
     *
     * @return array{0: ?Enrollment, 1: ?string}
     */
    private function releaseHoldToRegistrar(StudentProfile $profile): array
    {
        $enrollment = Enrollment::query()
            ->where('student_id', $profile->id)
            ->where('status', EnrollmentStatus::AwaitingFirstPayment->value)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($enrollment === null) {
            return [null, null];
        }

        $enrollment->update(['status' => EnrollmentStatus::PendingRegistrarApproval]);

        return [$enrollment, $enrollment->enrollmentSubjects()->with('section')->first()?->section->section_code];
    }

    private function holdAsCredit(StudentProfile $profile, string $amount, User $cashier, AuditRequestContext $context): void
    {
        $this->recordAccountPayment->execute($profile, $amount, $cashier, $context);
    }

    /**
     * Their turn at the Cashier is done either way. `ConfirmPayment` serves a ticket that
     * points at an enrollment; this covers a ticket still held by the profile alone.
     */
    private function serveTicket(StudentProfile $profile): void
    {
        QueueTicket::query()
            ->where('student_profile_id', $profile->id)
            ->whereIn('status', [QueueTicketStatus::Waiting, QueueTicketStatus::Serving])
            ->update(['status' => QueueTicketStatus::Served, 'served_at' => now()]);
    }

    /**
     * What was done the first time. The repeat's own `$amount` is only a fallback: it is not what
     * was recorded, so a retry that carries a different figure must not be reported as a payment.
     */
    private function existingResult(StudentProfile $profile, string $amount): FreshmanPaymentResult
    {
        $enrollment = Enrollment::query()
            ->where('student_id', $profile->id)
            ->whereNotIn('status', [EnrollmentStatus::Rejected, EnrollmentStatus::Cancelled, EnrollmentStatus::Withdrawn])
            ->orderByDesc('id')
            ->with(['payment', 'documents', 'assessment.items', 'enrollmentSubjects.section'])
            ->first();

        $blockCode = $enrollment?->enrollmentSubjects->first()?->section->section_code;

        $outcome = match (true) {
            $enrollment === null => FreshmanPaymentResult::AWAITING_SECTION,
            $enrollment->status === EnrollmentStatus::Enrolled => FreshmanPaymentResult::ENROLLED,
            default => FreshmanPaymentResult::AWAITING_REGISTRAR,
        };

        return new FreshmanPaymentResult(
            $outcome,
            false,
            $profile,
            $enrollment,
            $blockCode,
            $this->recordedAmount($profile, $enrollment) ?? $amount,
        );
    }

    /**
     * The money as the Cashier recorded it: the credit still held when there is no enrollment,
     * otherwise the enrollment's payment.
     */
    private function recordedAmount(StudentProfile $profile, ?Enrollment $enrollment): ?string
    {
        if ($enrollment !== null) {
            return $enrollment->payment?->amount;
        }

        $held = AccountPayment::query()
            ->where('student_id', $profile->id)
            ->whereNull('enrollment_id')
            ->sum('amount');

        return (float) $held > 0 ? number_format((float) $held, 2, '.', '') : null;
    }

    /**
     * What a freshman who paid and was submitted to a block is told: payment is in, and the
     * enrolled email comes only after the Registrar approves (not now).
     */
    private function awaitingRegistrarMessage(string $amount, ?string $blockCode): string
    {
        $paid = number_format((float) $amount, 2);
        $forBlock = $blockCode === null ? '' : " for block {$blockCode}";

        return "Your payment of PHP {$paid} has been received{$forBlock}. Your enrollment now waits for Registrar approval. You will get an email once you are officially enrolled, and you will not need to pay again.";
    }

    /**
     * What a transferee who paid before choosing their subjects is told.
     */
    private function transfereeHeldPaymentMessage(string $amount): string
    {
        $paid = number_format((float) $amount, 2);

        return "Your payment of PHP {$paid} has been received. Open Enrollment, choose your subjects and submit them; once Registrar Staff approve, you are enrolled and you will not need to pay again.";
    }

    /**
     * What a freshman whose payment is held is told. An irregular freshman is never put in a block
     * (they choose their own subjects), so "every section is full" would not be true for them.
     */
    private function heldPaymentMessage(StudentProfile $profile, string $amount): string
    {
        $paid = number_format((float) $amount, 2);

        if ($profile->enrollment_category === EnrollmentCategory::Irregular->value) {
            return "Your payment of PHP {$paid} has been received. Open Enrollment, choose your subjects and submit them; once Registrar Staff approve, you are enrolled and you will not need to pay again.";
        }

        return "Your payment of PHP {$paid} has been received. Every section for your year level is full right now. You will be notified as soon as a section opens, and you will not need to pay again.";
    }
}
