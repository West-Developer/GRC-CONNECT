<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Enrollment\EnrollmentDocumentType;
use App\Domain\Enrollment\FreshmanPaymentResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read FreshmanPaymentResult $resource
 */
final class FreshmanPaymentResource extends JsonResource
{
    /**
     * What confirming a freshman's first payment did (ADR 0043). `payment` and `document` are
     * null until the Registrar approves (2026-10-04); until then `held_amount` says how much the
     * Cashier received and holds as credit, whether or not the freshman was submitted to a block.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $result = $this->resource;
        $enrollment = $result->enrollment;
        $payment = $enrollment?->payment;
        $document = $enrollment?->documents->firstWhere('document_type', EnrollmentDocumentType::Cor);

        return [
            'type' => 'freshman_payment',
            'outcome' => $result->outcome,
            'block_code' => $result->blockCode,
            'student' => [
                'id' => $result->profile->id,
                'student_number' => $result->profile->student_number,
                'name' => $result->profile->user->name,
            ],
            'enrollment' => $enrollment === null ? null : [
                'id' => $enrollment->id,
                'status' => $enrollment->status->value,
                'total_units' => (float) $enrollment->total_units,
            ],
            'payment' => $payment === null ? null : [
                'external_reference' => $payment->external_reference,
                'amount' => $payment->amount,
                'promissory_note_on_file' => $payment->promissory_note_on_file,
                'confirmed_at' => $payment->confirmed_at->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
            'document' => $document === null ? null : [
                'id' => $document->id,
                'document_type' => $document->document_type->value,
                'document_number' => $document->document_number,
                'generated_at' => $document->generated_at->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
            'held_amount' => $payment === null ? $result->amount : null,
        ];
    }
}
