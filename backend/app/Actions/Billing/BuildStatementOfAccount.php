<?php

namespace App\Actions\Billing;

use App\Domain\Enrollment\EnrollmentStatus;
use App\Models\AccountPayment;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\StudentProfile;
use Carbon\CarbonInterface;

/**
 * A student's Statement of Account (stakeholder Doc 14, ADR 0036): for every
 * term that has an assessment, what was charged (tuition, miscellaneous fees,
 * scholarship discount), what was paid (the enrollment payment and any balance
 * payments allocated to that term, with the promissory note flag), and the
 * running balance carried into the next term. Advance payments that belong to
 * no term are listed as credits.
 *
 * Nothing is stored: like `BuildStudentAccountBalance` it is derived from the
 * assessments and payments, so the Student, the Cashier, and the printed copy
 * always agree. A term filter only narrows which terms are listed; the
 * balances still include every earlier term.
 */
final class BuildStatementOfAccount
{
    /**
     * @return array<string, mixed>
     */
    public function execute(StudentProfile $student, ?int $termFilterId = null): array
    {
        /** @var list<Enrollment> $enrollments */
        $enrollments = $student->enrollments()
            ->whereNotIn('status', [
                EnrollmentStatus::Rejected->value,
                EnrollmentStatus::Cancelled->value,
            ])
            ->has('assessment')
            ->with(['academicTerm', 'assessment.items', 'payment.confirmer'])
            ->get()
            ->sortBy(fn (Enrollment $enrollment): array => [$this->termOrder($enrollment), $enrollment->id])
            ->values()
            ->all();

        $accountPayments = $student->accountPayments()
            ->with('receiver')
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();
        $byEnrollment = $accountPayments->groupBy(fn (AccountPayment $payment): string => (string) $payment->enrollment_id);

        $terms = [];
        $runningBalance = '0.00';
        $totalAssessed = '0.00';
        $totalPaid = '0.00';

        foreach ($enrollments as $enrollment) {
            $assessment = $enrollment->getRelation('assessment');
            if (! $assessment instanceof Assessment || $assessment->total_amount === null) {
                continue;
            }

            $payments = [];
            $paid = '0.00';

            $enrollmentPayment = $enrollment->getRelation('payment');
            if ($enrollmentPayment instanceof Payment && $enrollmentPayment->amount !== null) {
                $paid = bcadd($paid, $enrollmentPayment->amount, 2);
                $payments[] = [
                    'kind' => 'enrollment_payment',
                    'label' => 'Enrollment confirmation payment',
                    'reference_number' => $enrollmentPayment->external_reference ?: sprintf('OR-EP%06d', $enrollmentPayment->id),
                    'amount' => $enrollmentPayment->amount,
                    'promissory_note_on_file' => (bool) $enrollmentPayment->promissory_note_on_file,
                    'paid_at' => $enrollmentPayment->confirmed_at->utc()->format('Y-m-d\TH:i:s\Z'),
                ];
            }

            foreach ($byEnrollment->get((string) $enrollment->id, collect()) as $accountPayment) {
                $paid = bcadd($paid, $accountPayment->amount, 2);
                $payments[] = [
                    'kind' => 'balance_payment',
                    'label' => 'Balance payment',
                    'reference_number' => sprintf('OR-BP%06d', $accountPayment->id),
                    'amount' => $accountPayment->amount,
                    'promissory_note_on_file' => false,
                    'paid_at' => $accountPayment->received_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                ];
            }

            $assessed = $assessment->total_amount;
            $outstanding = bccomp($assessed, $paid, 2) === 1 ? bcsub($assessed, $paid, 2) : '0.00';
            $priorBalance = $runningBalance;
            $runningBalance = bcadd($runningBalance, $outstanding, 2);
            $totalAssessed = bcadd($totalAssessed, $assessed, 2);
            $totalPaid = bcadd($totalPaid, $paid, 2);

            if ($termFilterId !== null && $enrollment->academic_term_id !== $termFilterId) {
                continue;
            }

            $discount = '0.00';
            $lines = [];
            foreach ($assessment->items as $item) {
                /** @var numeric-string $lineAmount */
                $lineAmount = $item->amount ?? '0.00';
                $lines[] = [
                    'category' => $item->category->value,
                    'label' => $item->label,
                    'quantity' => $item->quantity,
                    'unit_amount' => $item->unit_amount,
                    'amount' => $lineAmount,
                ];
                if ($item->category->value === 'scholarship_discount') {
                    $discount = bcadd($discount, $lineAmount, 2);
                }
            }

            $terms[] = [
                'academic_term_id' => $enrollment->academic_term_id,
                'label' => $enrollment->academicTerm->school_year.' · '.$enrollment->academicTerm->semester,
                'enrollment_id' => $enrollment->id,
                'enrollment_status' => $enrollment->status->value,
                'lines' => $lines,
                'scholarship_discount' => $discount,
                'assessment_total' => $assessed,
                'payments' => $payments,
                'paid_total' => $paid,
                'outstanding' => $outstanding,
                'prior_balance' => $priorBalance,
                'running_balance' => $runningBalance,
            ];
        }

        // Advance payments (no term) are credit against what is owed.
        $credits = [];
        foreach ($byEnrollment->get('', collect()) as $advance) {
            $totalPaid = bcadd($totalPaid, $advance->amount, 2);
            $credits[] = [
                'reference_number' => sprintf('OR-BP%06d', $advance->id),
                'amount' => $advance->amount,
                'received_at' => $advance->received_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            ];
        }

        $outstandingTotal = bccomp($totalAssessed, $totalPaid, 2) === 1 ? bcsub($totalAssessed, $totalPaid, 2) : '0.00';
        $advanceBalance = bccomp($totalPaid, $totalAssessed, 2) === 1 ? bcsub($totalPaid, $totalAssessed, 2) : '0.00';

        return [
            'student' => [
                'student_profile_id' => $student->id,
                'student_number' => $student->student_number,
                'name' => $student->user->name,
                'program_code' => $student->program->code,
                'program_name' => $student->program->name,
            ],
            'academic_term_id' => $termFilterId,
            'summary' => [
                'total_assessed' => $totalAssessed,
                'total_paid' => $totalPaid,
                'outstanding_balance' => $outstandingTotal,
                'advance_payment_balance' => $advanceBalance,
            ],
            'terms' => $terms,
            'credits' => $credits,
            'generated_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
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
