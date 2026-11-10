<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Enrollment\EnrollmentDocumentType;
use App\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Enrollment $resource
 */
final class PaymentConfirmationResource extends JsonResource
{
    /**
     * Bundles the now-`enrolled` enrollment with its `Payment` and Certificate
     * of Registration in one response, the same "return the aggregate" shape
     * `SubmitEnrollment`'s response already uses for its own five writes.
     *
     * @return array{
     *     enrollment: array<string, mixed>,
     *     payment: array{external_reference: ?string, amount: ?string, promissory_note_on_file: bool, confirmed_at: ?string},
     *     document: array{document_type: ?string, document_number: ?string, generated_at: ?string}
     * }
     */
    public function toArray(Request $request): array
    {
        $payment = $this->resource->payment;
        $document = $this->resource->documents->firstWhere('document_type', EnrollmentDocumentType::Cor);

        return [
            'enrollment' => EnrollmentResource::make($this->resource)->toArray($request),
            'payment' => [
                'external_reference' => $payment?->external_reference,
                'amount' => $payment?->amount,
                'promissory_note_on_file' => $payment->promissory_note_on_file ?? false,
                'confirmed_at' => $payment?->confirmed_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
            'document' => [
                'id' => $document?->id,
                'document_type' => $document?->document_type->value,
                'document_number' => $document?->document_number,
                'generated_at' => $document?->generated_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
        ];
    }
}
