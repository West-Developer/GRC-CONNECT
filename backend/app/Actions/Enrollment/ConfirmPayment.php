<?php

namespace App\Actions\Enrollment;

use App\Actions\Billing\ApplyFullPaymentDiscount;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\EnrollmentDocumentType;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Enrollment\QueueTicketStatus;
use App\Domain\Notifications\NotificationType;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\EnrollmentDocument;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\QueueTicket;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PRD §5.3 FR-FIN-007/008: confirms an externally received payment (no
 * gateway integration — see `Payment`'s own docblock) and generates the
 * Certificate of Registration in the same transaction, mirroring `SubmitEnrollment`'s
 * five-write shape: the `Payment` row, the enrollment's own transition to
 * `enrolled`, the `EnrollmentDocument` (COR), one audit entry, and the
 * confirmation notification.
 *
 * FR-FIN-009 idempotency rests on `payments.enrollment_id`'s unique
 * constraint: this Action checks for an existing `Payment` FIRST, before
 * checking the enrollment's status, so a repeat call — even one arriving
 * after the enrollment has already moved on to `enrolled` — returns the
 * existing records rather than erroring or creating a duplicate.
 *
 * No PDF pipeline: the approved COR print workflow renders protected structured data,
 * retention open, so `document_number` is an opaque deterministic string
 * (the same choice already made for `Q%06d` queue tickets) and
 * `storage_path` stays null. FR-FIN-010's "view and print/download" is
 * served by the Student module rendering this structured data as a
 * print-stylesheet page, not by generating a file here.
 *
 * Payment confirmation is also where each `EnrollmentSubject` transitions
 * `selected` → `enrolled` — the class roster (and grade submission's roster
 * filter) only ever surfaces `enrolled` rows, so a student stays invisible
 * to their professor until this step runs.
 *
 * ADR 0046: `payment_type` `full` (with the `quoted_amount` the Cashier was shown) takes the
 * full-payment discount, when one applies, before the payment row and the COR are written.
 *
 * ADR 0048, amended 2026-10-15: this is also where a new Transferee's or Returnee's 7-day
 * enrollment deadline ends. The student keeps the restricted portal and the deactivation notice
 * until the enrollment is finished AND paid, i.e. until it becomes `enrolled` here (a
 * pay-first student's Registrar approval reaches this through `SettleEnrollmentFromAdvanceCredit`).
 * The profile is therefore locked before the enrollment, the order used everywhere else.
 */
final readonly class ConfirmPayment
{
    /**
     * Everything `PaymentConfirmationResource` (and the `EnrollmentResource` it
     * embeds) reads, eager-loaded in one place: the subject rows are a
     * multi-row relation, so their `section` must not be lazy-loaded per row
     * (PRD §8.1, the N+1 guard).
     *
     * @var list<string>
     */
    private const RESPONSE_RELATIONS = [
        'student.user',
        'payment',
        'documents',
        'assessment.items',
        'academicTerm.enrollmentWindows',
        'queueTicket',
        'enrollmentSubjects.section.subject',
        'enrollmentSubjects.section.professor',
    ];

    public function __construct(
        private AuditRecorder $auditRecorder,
        private BuildCorSnapshot $buildCorSnapshot,
        private ApplyFullPaymentDiscount $applyFullPaymentDiscount,
        private PromissoryNoteRule $promissoryNoteRule,
    ) {}

    /**
     * `$freshmanFirstPayment` (ADR 0043) lifts the promissory-note requirement for a partial
     * payment: a freshman's first payment is at least the minimum and needs no note. Only the
     * freshman flows pass it; the Cashier's ordinary confirmation never does.
     *
     * @param  array<string, mixed>  $validated
     * @return array{enrollment: Enrollment, created: bool}
     */
    public function execute(
        Enrollment $enrollment,
        array $validated,
        User $actor,
        AuditRequestContext $context,
        bool $freshmanFirstPayment = false,
    ): array {
        return DB::transaction(function () use ($enrollment, $validated, $actor, $context, $freshmanFirstPayment): array {
            // PROFILE -> ENROLLMENT -> SECTIONS: the student's profile first, so ending the deadline below
            // cannot deadlock with a step that locks the profile before the enrollment.
            $lockedStudent = StudentProfile::query()->whereKey($enrollment->student_id)->lockForUpdate()->first();

            $lockedEnrollment = Enrollment::query()
                ->whereKey($enrollment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existingPayment = Payment::query()->where('enrollment_id', $lockedEnrollment->id)->first();

            if ($existingPayment !== null) {
                return [
                    'enrollment' => $lockedEnrollment->load(self::RESPONSE_RELATIONS),
                    'created' => false,
                ];
            }

            if ($lockedEnrollment->status !== EnrollmentStatus::PendingPayment) {
                throw ValidationException::withMessages([
                    'enrollment' => 'Payment can only be confirmed for an enrollment that is currently '.
                        "'pending_payment'; it is currently '{$lockedEnrollment->status->value}'.",
                ]);
            }

            $confirmedAt = now();

            // An explicit enrollment payment has already passed the PHP
            // 1,000.00 minimum in ConfirmPaymentRequest. A partial payment
            // therefore still finalizes enrollment and generates the COR;
            // only an omitted amount falls back to what was assessed. An
            // enrollment with no assessment (created directly by legacy test
            // fixtures) keeps the established behavior of staying null.
            $assessment = Assessment::query()->where('enrollment_id', $lockedEnrollment->id)->lockForUpdate()->first();
            $amount = $validated['amount'] ?? $assessment?->total_amount;

            // ADR 0046: Pay in full. Any discount is written here, in the same transaction, so the
            // payment, the assessment and the COR snapshot below all agree on the net. A stale
            // `quoted_amount` throws before anything is written. An installment, and a request with
            // no `payment_type`, are not touched: no discount and no extra fee.
            if (($validated['payment_type'] ?? null) === 'full' && ! $freshmanFirstPayment && $assessment instanceof Assessment) {
                // ConfirmPaymentRequest only lets a plain decimal through.
                /** @var numeric-string $quotedAmount */
                $quotedAmount = (string) $validated['quoted_amount'];

                $amount = $this->applyFullPaymentDiscount->forCashierPayment($assessment, $quotedAmount, $actor, $context);
            }

            $promissoryNoteOnFile = (bool) ($validated['promissory_note_on_file'] ?? false);

            // A promissory note is only for a student with a previous bill (`PromissoryNoteRule`, owner 2026-10-13).
            if (! $freshmanFirstPayment
                && ! $promissoryNoteOnFile
                && $this->promissoryNoteRule->isRequired($lockedEnrollment, $amount, $assessment?->total_amount)) {
                throw ValidationException::withMessages([
                    'promissory_note_on_file' => 'A promissory note on file is required for partial payments.',
                ]);
            }

            $payment = Payment::create([
                'enrollment_id' => $lockedEnrollment->id,
                'confirmed_by' => $actor->id,
                'external_reference' => $validated['external_reference'] ?? null,
                'amount' => $amount,
                'promissory_note_on_file' => $validated['promissory_note_on_file'] ?? false,
                'confirmed_at' => $confirmedAt,
            ]);

            // A freshman's Cashier payment stamps this before the Registrar approves (ADR 0043,
            // 2026-10-04), so keep that moment rather than the later completion.
            $lockedEnrollment->update([
                'status' => EnrollmentStatus::Enrolled,
                'payment_confirmed_at' => $lockedEnrollment->payment_confirmed_at ?? $confirmedAt,
                'enrolled_at' => $confirmedAt,
            ]);
            $lockedEnrollment->refresh();

            // Finished and paid: the student has all their options for good and the 7-day
            // deactivation no longer applies (ADR 0048, amended 2026-10-15).
            if ($lockedStudent?->enrollment_deadline_at !== null) {
                $lockedStudent->forceFill(['enrollment_deadline_at' => null])->save();
            }

            QueueTicket::query()
                ->where('enrollment_id', $lockedEnrollment->id)
                ->whereIn('status', [QueueTicketStatus::Waiting, QueueTicketStatus::Serving])
                ->update([
                    'status' => QueueTicketStatus::Served,
                    'served_at' => $confirmedAt,
                ]);

            $lockedEnrollment->enrollmentSubjects()
                ->where('status', EnrollmentSubjectStatus::Selected)
                ->update(['status' => EnrollmentSubjectStatus::Enrolled]);

            $payment->setRelation('confirmer', $actor);
            $snapshot = $this->buildCorSnapshot->execute(
                $lockedEnrollment->fresh([
                    'student.user',
                    'student.program',
                    'academicTerm',
                    'enrollmentSubjects.section.subject',
                    'assessment.items',
                ]),
                $payment,
            );

            $document = EnrollmentDocument::create([
                'enrollment_id' => $lockedEnrollment->id,
                'document_type' => EnrollmentDocumentType::Cor,
                'document_number' => sprintf('COR%06d', $lockedEnrollment->id),
                'storage_path' => null,
                'snapshot' => $snapshot,
                'content_hash' => $this->buildCorSnapshot->hash($snapshot),
                'generated_at' => $confirmedAt,
            ]);

            $this->auditRecorder->record(
                $actor,
                AuditAction::ENROLLMENT_PAYMENT_CONFIRMED,
                AuditableType::ENROLLMENT,
                $lockedEnrollment->id,
                ['status' => EnrollmentStatus::PendingPayment->value],
                [
                    'status' => $lockedEnrollment->status->value,
                    'payment_id' => $payment->id,
                    'document_number' => $document->document_number,
                ],
                null,
                $context,
            );

            Notification::create([
                'user_id' => $lockedEnrollment->student->user_id,
                'type' => NotificationType::EnrollmentPaymentConfirmed,
                'message' => "Your payment has been confirmed. Your Certificate of Registration ({$document->document_number}) is ready.",
            ]);

            return [
                'enrollment' => $lockedEnrollment->load(self::RESPONSE_RELATIONS),
                'created' => true,
            ];
        });
    }
}
