<?php

namespace App\Actions\Billing;

use App\Actions\Enrollment\BuildCorSnapshot;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Billing\StudentAccountBalance;
use App\Domain\Enrollment\EnrollmentDocumentType;
use App\Models\AccountPayment;
use App\Models\Enrollment;
use App\Models\EnrollmentDocument;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a balance-only Cashier payment against the oldest outstanding
 * active enrollment(s). When the receipt is larger than the oldest balance,
 * the remainder is allocated to the next oldest balance in the same
 * transaction. Locking the Student profile serializes concurrent receipts
 * for that account; this action deliberately has no QueueTicket dependency
 * or side effect.
 */
final readonly class RecordAccountPayment
{
    public function __construct(
        private BuildStudentAccountBalance $buildStudentAccountBalance,
        private AuditRecorder $auditRecorder,
        private BuildCorSnapshot $buildCorSnapshot,
    ) {}

    public function execute(
        StudentProfile $student,
        string $amount,
        User $actor,
        AuditRequestContext $context,
    ): StudentAccountBalance {
        return DB::transaction(function () use ($student, $amount, $actor, $context): StudentAccountBalance {
            $lockedStudent = StudentProfile::query()
                ->whereKey($student->id)
                ->lockForUpdate()
                ->firstOrFail();
            $balance = $this->buildStudentAccountBalance->execute($lockedStudent);

            if (! is_numeric($amount) || bccomp($amount, '0.00', 2) !== 1) {
                throw ValidationException::withMessages([
                    'amount' => 'Account payment amount must be greater than zero.',
                ]);
            }

            $remainingAmount = $amount;
            $receivedAt = now();
            /** @var list<array{enrollment_id: ?int, amount: string}> $allocations */
            $allocations = [];
            $firstPayment = null;

            foreach ($balance->entries as $entry) {
                if (bccomp($remainingAmount, '0.00', 2) !== 1) {
                    break;
                }

                $allocatedAmount = bccomp($remainingAmount, $entry->outstandingBalance, 2) === 1
                    ? $entry->outstandingBalance
                    : $remainingAmount;
                $payment = AccountPayment::create([
                    'student_id' => $lockedStudent->id,
                    'enrollment_id' => $entry->enrollmentId,
                    'received_by' => $actor->id,
                    'amount' => $allocatedAmount,
                    'received_at' => $receivedAt,
                ]);
                $firstPayment ??= $payment;
                $allocations[] = [
                    'enrollment_id' => $entry->enrollmentId,
                    'amount' => $payment->amount,
                ];
                $remainingAmount = bcsub($remainingAmount, $allocatedAmount, 2);
            }

            if (bccomp($remainingAmount, '0.00', 2) === 1) {
                $payment = AccountPayment::create([
                    'student_id' => $lockedStudent->id,
                    'enrollment_id' => null,
                    'received_by' => $actor->id,
                    'amount' => $remainingAmount,
                    'received_at' => $receivedAt,
                ]);
                $firstPayment ??= $payment;
                $allocations[] = [
                    'enrollment_id' => null,
                    'amount' => $payment->amount,
                ];
                $remainingAmount = '0.00';
            }

            if ($firstPayment === null) {
                throw new \LogicException('Account-payment allocation did not produce a payment record.');
            }

            $enrollmentIds = collect($allocations)
                ->pluck('enrollment_id')
                ->filter()
                ->unique();

            foreach ($enrollmentIds as $enrollmentId) {
                $document = EnrollmentDocument::query()
                    ->where('enrollment_id', $enrollmentId)
                    ->where('document_type', EnrollmentDocumentType::Cor)
                    ->first();

                if ($document !== null) {
                    $enrollment = Enrollment::query()
                        ->with([
                            'student.user',
                            'student.program',
                            'academicTerm',
                            'enrollmentSubjects.section.subject',
                            'assessment.items',
                            'payment.confirmer',
                        ])
                        ->find($enrollmentId);

                    if ($enrollment !== null) {
                        $newSnapshot = $this->buildCorSnapshot->execute($enrollment, $enrollment->payment);
                        $document->update([
                            'snapshot' => $newSnapshot,
                            'content_hash' => $this->buildCorSnapshot->hash($newSnapshot),
                        ]);
                    }
                }
            }

            $this->auditRecorder->record(
                $actor,
                AuditAction::ACCOUNT_PAYMENT_RECORDED,
                AuditableType::ACCOUNT_PAYMENT,
                $firstPayment->id,
                [
                    'student_id' => $lockedStudent->id,
                    'outstanding_balance' => $balance->outstandingBalance,
                ],
                [
                    'amount' => $amount,
                    'allocations' => $allocations,
                ],
                null,
                $context,
            );

            return $this->buildStudentAccountBalance->execute($lockedStudent);
        });
    }
}
