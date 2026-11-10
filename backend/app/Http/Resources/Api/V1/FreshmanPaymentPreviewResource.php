<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Enrollment\FreshmanPaymentPreview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read FreshmanPaymentPreview $resource
 */
final class FreshmanPaymentPreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $preview = $this->resource;
        $block = $preview->block;
        $enrollment = $preview->enrollment;

        return [
            'type' => 'freshman_payment_preview',
            'student_id' => $preview->profile->id,
            'student_number' => $preview->profile->student_number,
            'student_name' => $preview->profile->user->name,
            'student_type' => $preview->profile->student_type?->value,
            'ticket_number' => $preview->ticketNumber,
            'minimum_amount' => $preview->minimumAmount,
            'already_confirmed' => $preview->alreadyConfirmed,
            'can_enroll' => $block !== null || $enrollment !== null,
            // A transferee's own chosen subjects waiting at the payment hold; null for a freshman.
            'enrollment' => $enrollment === null ? null : [
                'id' => $enrollment->id,
                'status' => $enrollment->status->value,
                'status_label' => $enrollment->status->label(),
                'total_units' => (float) $enrollment->total_units,
                'subjects' => $enrollment->enrollmentSubjects
                    ->filter(fn ($enrollmentSubject): bool => $enrollmentSubject->status->occupiesSeat())
                    ->map(fn ($enrollmentSubject): array => [
                        'code' => $enrollmentSubject->section->subject->code,
                        'title' => $enrollmentSubject->section->subject->title,
                        'units' => (float) $enrollmentSubject->section->subject->units,
                    ])
                    ->values()
                    ->all(),
            ],
            'block' => $block === null ? null : [
                'block_code' => $block->blockCode,
                'total_units' => $block->totalUnits,
                'seats_remaining' => $block->seatsRemaining,
                'subjects' => array_map(fn ($section): array => [
                    'code' => $section->subject->code,
                    'title' => $section->subject->title,
                    'units' => (float) $section->subject->units,
                ], $block->sections),
            ],
            'estimated_total' => $preview->estimatedTotal,
            // ADR 0046: what paying in full collects. All keys are always present; with no
            // estimate yet (`no_estimate`) the amounts are null and a full payment is refused.
            'full_payment' => [
                'applies' => $preview->fullPayment?->applies() ?? false,
                'percentage' => $preview->fullPayment?->percentage,
                'discount_amount' => $preview->fullPayment?->discountAmount,
                'net_total' => $preview->fullPayment?->netTotal,
                'reason' => $preview->fullPayment?->reason,
            ],
        ];
    }
}
