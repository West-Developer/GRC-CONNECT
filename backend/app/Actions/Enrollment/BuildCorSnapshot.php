<?php

namespace App\Actions\Enrollment;

use App\Domain\Billing\AssessmentItemCategory;
use App\Domain\Enrollment\CorTerms;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Models\AccountPayment;
use App\Models\Enrollment;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use JsonException;

final class BuildCorSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public function execute(Enrollment $enrollment, ?Payment $payment): array
    {
        $student = $enrollment->student;
        $assessment = $enrollment->assessment;
        $items = $assessment?->items->sortBy('id')->values() ?? collect();
        $tuition = $items
            ->filter(fn ($item): bool => $item->category === AssessmentItemCategory::Tuition)
            ->map(fn ($item): array => $this->feeItem($item))
            ->values()
            ->all();
        $otherFees = $this->otherFees(
            $items->filter(fn ($item): bool => $item->category === AssessmentItemCategory::Miscellaneous),
        );
        // ADR 0025: a scholarship is a negative line, so tuition + other fees +
        // the two discount sums always add up to the grand total. Empty when there is none.
        $scholarshipDiscount = array_values($items
            ->filter(fn ($item): bool => $item->category === AssessmentItemCategory::ScholarshipDiscount)
            ->map(fn ($item): array => $this->feeItem($item))
            ->all());
        // ADR 0046: the full-payment discount is a second negative line, written at payment
        // confirmation. Empty when there is none.
        $fullPaymentDiscount = array_values($items
            ->filter(fn ($item): bool => $item->category === AssessmentItemCategory::FullPaymentDiscount)
            ->map(fn ($item): array => $this->feeItem($item))
            ->all());

        $additionalPayments = '0.00';
        if ($enrollment->exists) {
            $sum = AccountPayment::query()->where('enrollment_id', $enrollment->id)->sum('amount');
            $additionalPayments = number_format((float) $sum, 2, '.', '');
        } elseif ($enrollment->relationLoaded('accountPayments')) {
            $sum = $enrollment->accountPayments->sum(fn ($p): float => (float) $p->amount);
            $additionalPayments = number_format((float) $sum, 2, '.', '');
        }

        $grandTotal = $assessment?->total_amount ?? '0.00';
        $initialPayment = $payment?->amount ?? '0.00';
        $totalPaid = bcadd($initialPayment, $additionalPayments, 2);
        $diff = bcsub($grandTotal, $totalPaid, 2);
        $remainingBalance = bccomp($diff, '0.00', 2) === -1 ? '0.00' : $diff;

        $paymentStatus = match (true) {
            $payment === null && bccomp($totalPaid, '0.00', 2) === 0 => 'unpaid',
            bccomp($remainingBalance, '0.00', 2) === 0 => 'full_payment',
            default => 'partial_payment',
        };

        return [
            'document_title' => 'Certificate of Registration',
            'institution' => [
                'name' => 'Global Reciprocal Colleges',
                'address' => 'GRC Building, 454, 1400 Rizal Ave Ext, East Grace Park, Caloocan, Metro Manila',
            ],
            'student' => [
                'student_number' => $student->student_number,
                'name' => $student->user->name,
                'address' => filled($student->address) ? $student->address : 'Not provided',
                'course' => $student->program->name,
                'level' => 'Year '.$student->year_level,
                // Set once per term by the Registrar Head (stakeholder Doc 14).
                'platform' => $enrollment->academicTerm->enrollment_platform?->label() ?? 'Not provided',
                'classification' => $student->financial_status?->label() ?? 'Payee',
            ],
            'term' => [
                'school_year' => $enrollment->academicTerm->school_year,
                'semester' => $enrollment->academicTerm->semester,
            ],
            'subjects' => $enrollment->enrollmentSubjects
                ->filter(fn ($subject): bool => $subject->status !== EnrollmentSubjectStatus::Dropped)
                ->sortBy(fn ($subject): string => $subject->section->subject->code)
                ->map(fn ($subject): array => [
                    'code' => $subject->section->subject->code,
                    'title' => $subject->section->subject->title,
                    'units' => number_format($subject->section->subject->units, 2, '.', ''),
                    'section' => $subject->section->section_code,
                    'schedule_id' => (string) $subject->section_id,
                    'schedule' => $this->schedule($subject->section->schedule_days, $subject->section->starts_at_time, $subject->section->ends_at_time),
                    'room' => $subject->section->room ?? 'Not provided',
                ])
                ->values()
                ->all(),
            'total_units' => number_format($enrollment->total_units, 2, '.', ''),
            'admission_certification' => sprintf(
                'This is to certify that %s is cleared and enrolled for SY %s, %s for %s, %s.',
                $student->user->name,
                $enrollment->academicTerm->school_year,
                $enrollment->academicTerm->semester,
                $student->program->name,
                'Year '.$student->year_level,
            ),
            'fees' => [
                'currency' => $assessment?->currency ?? 'PHP',
                'tuition' => $tuition,
                'other_fees' => $otherFees,
                'total_tuition' => $this->sum($tuition),
                'total_other_fees' => $this->sum($otherFees),
                'scholarship_discount' => $scholarshipDiscount,
                'total_scholarship_discount' => $this->sum($scholarshipDiscount),
                'full_payment_discount' => $fullPaymentDiscount,
                'total_full_payment_discount' => $this->sum($fullPaymentDiscount),
                'grand_total' => $grandTotal,
                'initial_payment' => $initialPayment,
                'additional_payments' => $additionalPayments,
                'payment_amount' => $totalPaid,
                'amount_paid' => $totalPaid,
                'remaining_balance' => $remainingBalance,
                'payment_status' => $paymentStatus,
                'payment_reference' => $payment?->external_reference,
                'promissory_note_on_file' => (bool) ($payment?->promissory_note_on_file ?? false),
                'confirmed_at' => $payment?->confirmed_at?->toIso8601String(),
            ],
            'signatories' => [
                'cashier' => $payment?->confirmer?->name ?? 'Not provided',
                'registrar' => 'Registrar',
            ],
            'withdrawal_terms' => CorTerms::all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function hash(array $snapshot): string
    {
        try {
            return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new \LogicException('The COR snapshot could not be encoded.', previous: $exception);
        }
    }

    /**
     * @return array{label: string, quantity: ?string, unit_amount: ?string, amount: string}
     */
    private function feeItem(object $item): array
    {
        return [
            'label' => $item->label,
            'quantity' => $item->quantity,
            'unit_amount' => $item->unit_amount,
            'amount' => $item->amount ?? '0.00',
        ];
    }

    /**
     * The reference COR always reserves these lines. Amounts come only from
     * the student's assessment: a non-assessed fee is visible as 0.00 rather
     * than silently disappearing or being invented as a charge.
     *
     * @param  Collection<int, object>  $items
     * @return list<array{label: string, quantity: ?string, unit_amount: ?string, amount: string}>
     */
    private function otherFees(Collection $items): array
    {
        $remaining = $items->values();
        $fees = [];

        foreach (self::OTHER_FEE_LABELS as $label) {
            $index = $remaining->search(fn (object $item): bool => $this->canonicalOtherFeeLabel($item->label) === $label);
            if ($index === false) {
                $fees[] = [
                    'label' => $label,
                    'quantity' => null,
                    'unit_amount' => null,
                    'amount' => '0.00',
                ];

                continue;
            }

            $item = $remaining->get($index);
            $fees[] = [
                ...$this->feeItem($item),
                'label' => $label,
            ];
            $remaining->forget($index);
        }

        return [
            ...$fees,
            ...$remaining->map(fn (object $item): array => $this->feeItem($item))->values()->all(),
        ];
    }

    private function canonicalOtherFeeLabel(string $label): ?string
    {
        $normalized = (string) preg_replace('/[^a-z0-9]+/', '', strtolower($label));

        return match ($normalized) {
            'registration' => 'Registration',
            'guidanceandcounselingandstudentaffair', 'guidanceandcounsellingandstudentaffair' => 'Guidance and Counseling and Student Affair',
            'medicalanddental' => 'Medical and Dental',
            'studentinformationsystemfee', 'sisfee' => 'Student Information System Fee',
            'energywatercommunicationfees', 'energywatercommunicationfee' => 'Energy/Water/Communication Fees',
            'communityextensionfee' => 'Community Extension Fee',
            'researchpublication', 'researchandpublication' => 'Research & Publication',
            'computerlabfee1allstudents', 'computerlabfee1', 'laboratory' => 'Computer Lab Fee 1 (All Students)',
            'studentid', 'studentidentification' => 'Student I.D.',
            'developmentfee' => 'Development Fee',
            'postal' => 'Postal',
            'computerlabfee2bsit', 'computerlabfee2' => 'Computer Lab Fee 2 (BSIT)',
            'sportsdevelopmentfee' => 'Sports Development Fee',
            'handbook' => 'Hand Book',
            'library', 'libraryfee' => 'Library Fee',
            default => null,
        };
    }

    /** @var list<string> */
    private const OTHER_FEE_LABELS = [
        'Registration',
        'Guidance and Counseling and Student Affair',
        'Medical and Dental',
        'Student Information System Fee',
        'Energy/Water/Communication Fees',
        'Community Extension Fee',
        'Research & Publication',
        'Computer Lab Fee 1 (All Students)',
        'Student I.D.',
        'Development Fee',
        'Postal',
        'Computer Lab Fee 2 (BSIT)',
        'Sports Development Fee',
        'Hand Book',
        'Library Fee',
    ];

    /**
     * @param  list<array{label: string, quantity: ?string, unit_amount: ?string, amount: string}>  $items
     */
    private function sum(array $items): string
    {
        return array_reduce(
            $items,
            fn (string $total, array $item): string => bcadd($total, $item['amount'], 2),
            '0.00',
        );
    }

    private function schedule(?string $days, ?string $startsAt, ?string $endsAt): string
    {
        if ($days === null || $startsAt === null || $endsAt === null) {
            return 'Not provided';
        }

        return CarbonImmutable::createFromFormat('H:i:s', $startsAt)->format('h:i A')
            .' - '.CarbonImmutable::createFromFormat('H:i:s', $endsAt)->format('h:i A')
            .' '.ucwords(strtolower($days));
    }
}
