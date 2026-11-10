<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Billing\StudentAccountBalance;
use App\Domain\Billing\StudentAccountBalanceEntry;
use App\Models\StudentProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read StudentProfile $resource
 */
final class StudentAccountResource extends JsonResource
{
    public function __construct(StudentProfile $resource, private readonly StudentAccountBalance $balance)
    {
        parent::__construct($resource);
    }

    /**
     * @return array{
     *     type: string,
     *     student_id: int,
     *     student_name: string,
     *     student_number: string,
     *     year_level: int,
     *     currency: string,
     *     total_assessed: string,
     *     total_paid: string,
     *     prior_balance: string,
     *     outstanding_balance: string,
     *     advance_payment_balance: string,
     *     has_promissory_note_on_file: bool,
     *     entries: list<array{enrollment_id: int, academic_term_id: int, academic_term_label: string, assessment_amount: string, confirmed_payment_amount: string, account_payment_amount: string, outstanding_balance: string, promissory_note_on_file: bool}>
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'student_account',
            'student_id' => $this->resource->id,
            'student_name' => $this->resource->user->name,
            'student_number' => $this->resource->student_number,
            'year_level' => $this->resource->year_level,
            'financial_status' => $this->resource->financial_status?->value ?? 'payee',
            'financial_status_label' => $this->resource->financial_status?->label() ?? 'Payee',
            'currency' => 'PHP',
            'total_assessed' => $this->balance->totalAssessed,
            'total_paid' => $this->balance->totalPaid,
            'prior_balance' => $this->balance->priorBalance,
            'outstanding_balance' => $this->balance->outstandingBalance,
            'advance_payment_balance' => $this->balance->advancePaymentBalance,
            'has_promissory_note_on_file' => $this->balance->hasPromissoryNoteOnFile,
            'entries' => array_map(
                static fn (StudentAccountBalanceEntry $entry): array => [
                    'enrollment_id' => $entry->enrollmentId,
                    'academic_term_id' => $entry->academicTermId,
                    'academic_term_label' => $entry->academicTermLabel,
                    'assessment_amount' => $entry->assessmentAmount,
                    'confirmed_payment_amount' => $entry->confirmedPaymentAmount,
                    'account_payment_amount' => $entry->accountPaymentAmount,
                    'outstanding_balance' => $entry->outstandingBalance,
                    'promissory_note_on_file' => $entry->promissoryNoteOnFile,
                ],
                $this->balance->entries,
            ),
            'transactions' => $this->balance->transactions,
        ];
    }
}
