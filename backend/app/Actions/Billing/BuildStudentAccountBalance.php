<?php

namespace App\Actions\Billing;

use App\Domain\Billing\StudentAccountBalance;
use App\Domain\Billing\StudentAccountBalanceEntry;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\StudentProfile;
use Carbon\CarbonInterface;

/**
 * The authoritative account view for a Student. It derives outstanding
 * balance from nonterminal assessments less the original enrollment payment
 * and every separately allocated account payment. No editable balance is
 * stored, so the Cashier and Student always see the same audit-backed total.
 */
final class BuildStudentAccountBalance
{
    public function execute(StudentProfile $student): StudentAccountBalance
    {
        /** @var list<Enrollment> $enrollments */
        $enrollments = $student->enrollments()
            ->whereNotIn('status', EnrollmentStatus::terminalValues())
            ->has('assessment')
            ->with(['academicTerm', 'assessment', 'payment', 'accountPayments'])
            ->get()
            ->sortBy(fn (Enrollment $enrollment): array => [
                $this->termOrder($enrollment),
                $enrollment->id,
            ])
            ->values()
            ->all();

        $allAccountPayments = $student->accountPayments()
            ->with(['receiver', 'enrollment.academicTerm'])
            ->orderBy('received_at')
            ->get();

        $accountPaymentsByEnrollment = [];
        $totalAccountPayments = '0.00';
        foreach ($allAccountPayments as $accountPayment) {
            $totalAccountPayments = bcadd($totalAccountPayments, $accountPayment->amount, 2);
            if ($accountPayment->enrollment_id !== null) {
                $accountPaymentsByEnrollment[$accountPayment->enrollment_id] = bcadd(
                    $accountPaymentsByEnrollment[$accountPayment->enrollment_id] ?? '0.00',
                    $accountPayment->amount,
                    2,
                );
            }
        }

        $totalAssessed = '0.00';
        $totalConfirmedPayments = '0.00';
        $priorBalance = '0.00';
        $hasPromissoryNoteOnFile = false;
        $entries = [];
        $currentEnrollmentId = $enrollments === [] ? null : $enrollments[array_key_last($enrollments)]->id;

        foreach ($enrollments as $enrollment) {
            $assessment = $enrollment->getRelation('assessment');

            if (! $assessment instanceof Assessment || $assessment->total_amount === null) {
                continue;
            }

            $assessmentAmount = $assessment->total_amount;
            $payment = $enrollment->getRelation('payment');
            $confirmedPaymentAmount = $payment instanceof Payment && $payment->amount !== null
                ? $payment->amount
                : '0.00';
            $accountPaymentAmount = $accountPaymentsByEnrollment[$enrollment->id] ?? '0.00';

            $paidForEnrollment = bcadd($confirmedPaymentAmount, $accountPaymentAmount, 2);
            $computedOutstanding = bcsub($assessmentAmount, $paidForEnrollment, 2);
            $entryOutstanding = bccomp($computedOutstanding, '0.00', 2) === -1
                ? '0.00'
                : $computedOutstanding;

            $totalAssessed = bcadd($totalAssessed, $assessmentAmount, 2);
            $totalConfirmedPayments = bcadd($totalConfirmedPayments, $confirmedPaymentAmount, 2);

            if ($enrollment->id !== $currentEnrollmentId) {
                $priorBalance = bcadd($priorBalance, $entryOutstanding, 2);
            }

            if (bccomp($entryOutstanding, '0.00', 2) !== 1) {
                continue;
            }

            $promissoryNoteOnFile = $payment instanceof Payment && $payment->promissory_note_on_file;
            $hasPromissoryNoteOnFile = $hasPromissoryNoteOnFile || $promissoryNoteOnFile;
            $entries[] = new StudentAccountBalanceEntry(
                enrollmentId: $enrollment->id,
                academicTermId: $enrollment->academic_term_id,
                academicTermLabel: $enrollment->academicTerm->school_year.' · '.$enrollment->academicTerm->semester,
                assessmentAmount: $assessmentAmount,
                confirmedPaymentAmount: $confirmedPaymentAmount,
                accountPaymentAmount: $accountPaymentAmount,
                outstandingBalance: $entryOutstanding,
                promissoryNoteOnFile: $promissoryNoteOnFile,
            );
        }

        $totalPaid = bcadd($totalConfirmedPayments, $totalAccountPayments, 2);
        $outstandingBalance = bccomp($totalAssessed, $totalPaid, 2) === 1
            ? bcsub($totalAssessed, $totalPaid, 2)
            : '0.00';
        $advancePaymentBalance = bccomp($totalPaid, $totalAssessed, 2) === 1
            ? bcsub($totalPaid, $totalAssessed, 2)
            : '0.00';

        $transactions = [];

        foreach ($enrollments as $enrollment) {
            $termLabel = $enrollment->academicTerm
                ? $enrollment->academicTerm->school_year.' · '.$enrollment->academicTerm->semester
                : 'Current Term';

            $payment = $enrollment->getRelation('payment');
            if ($payment instanceof Payment && $payment->amount !== null) {
                $payment->loadMissing('confirmer');
                $transactions[] = [
                    'id' => 'enrollment_payment:'.$payment->id,
                    'transaction_type' => 'enrollment_payment',
                    'transaction_type_label' => 'Enrollment Confirmation Payment',
                    'enrollment_id' => $enrollment->id,
                    'academic_term_label' => $termLabel,
                    'amount' => $payment->amount,
                    'reference_number' => $payment->external_reference ?: sprintf('OR-EP%06d', $payment->id),
                    'cashier_name' => $payment->confirmer?->name ?? 'Cashier Staff',
                    'promissory_note_on_file' => (bool) $payment->promissory_note_on_file,
                    'processed_at' => $payment->confirmed_at?->utc()->format('Y-m-d\TH:i:s\Z') ?? now()->utc()->format('Y-m-d\TH:i:s\Z'),
                ];
            }
        }

        foreach ($allAccountPayments as $accountPayment) {
            $termLabel = $accountPayment->enrollment?->academicTerm
                ? $accountPayment->enrollment->academicTerm->school_year.' · '.$accountPayment->enrollment->academicTerm->semester
                : 'Advance Payment / Credit';

            $transactions[] = [
                'id' => 'account_payment:'.$accountPayment->id,
                'transaction_type' => 'account_payment',
                'transaction_type_label' => $accountPayment->enrollment_id === null ? 'Advance Payment / Credit' : 'Balance Settlement Payment',
                'enrollment_id' => $accountPayment->enrollment_id,
                'academic_term_label' => $termLabel,
                'amount' => $accountPayment->amount,
                'reference_number' => sprintf('OR-BP%06d', $accountPayment->id),
                'cashier_name' => $accountPayment->receiver?->name ?? 'Cashier Staff',
                'promissory_note_on_file' => false,
                'processed_at' => $accountPayment->received_at?->utc()->format('Y-m-d\TH:i:s\Z') ?? now()->utc()->format('Y-m-d\TH:i:s\Z'),
            ];
        }

        usort($transactions, fn ($a, $b) => strcmp($b['processed_at'], $a['processed_at']));

        return new StudentAccountBalance(
            totalAssessed: $totalAssessed,
            totalPaid: $totalPaid,
            priorBalance: $priorBalance,
            outstandingBalance: $outstandingBalance,
            advancePaymentBalance: $advancePaymentBalance,
            hasPromissoryNoteOnFile: $hasPromissoryNoteOnFile,
            entries: $entries,
            transactions: $transactions,
        );
    }

    private function termOrder(Enrollment $enrollment): int
    {
        $termStart = $enrollment->academicTerm->starts_at;

        if ($termStart instanceof CarbonInterface) {
            return $termStart->getTimestamp();
        }

        $assessment = $enrollment->getRelation('assessment');

        return $assessment instanceof Assessment
            ? $assessment->assessed_at->getTimestamp()
            : PHP_INT_MAX;
    }
}
