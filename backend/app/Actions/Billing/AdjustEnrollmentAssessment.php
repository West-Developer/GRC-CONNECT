<?php

namespace App\Actions\Billing;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Billing\AssessmentItemCategory;
use App\Domain\Billing\ScholarshipTier;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Models\Assessment;
use App\Models\AssessmentItem;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A controlled pre-payment correction of one student's financial assessment.
 * It never changes enrollment subjects, and the payment/COR boundary is
 * immutable: payment or a generated COR permanently prevents this action.
 */
final readonly class AdjustEnrollmentAssessment
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * @param  array{reason: string, items: list<array{id: int, amount?: ?string, unit_amount?: ?string}>}  $validated
     */
    public function execute(Enrollment $enrollment, array $validated, User $actor, AuditRequestContext $context): Enrollment
    {
        return DB::transaction(function () use ($enrollment, $validated, $actor, $context): Enrollment {
            $lockedEnrollment = Enrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();

            if ($lockedEnrollment->status !== EnrollmentStatus::PendingPayment) {
                throw ValidationException::withMessages([
                    'enrollment' => 'Fees can only be adjusted while the enrollment is pending payment.',
                ]);
            }

            if (Payment::query()->where('enrollment_id', $lockedEnrollment->id)->exists()) {
                throw ValidationException::withMessages([
                    'enrollment' => 'Fees cannot be adjusted after payment confirmation.',
                ]);
            }

            $assessment = Assessment::query()
                ->where('enrollment_id', $lockedEnrollment->id)
                ->lockForUpdate()
                ->first();

            if (! $assessment instanceof Assessment) {
                throw ValidationException::withMessages([
                    'assessment' => 'This enrollment does not have an assessment to adjust.',
                ]);
            }

            /** @var Collection<int, AssessmentItem> $items */
            $items = $assessment->items()->lockForUpdate()->get()->keyBy('id');
            // A scholarship line (ADR 0025) is derived, never edited by hand: it
            // is left out of the lines the Cashier submits and recomputed from
            // the adjusted base below.
            $discountItem = $items->first(fn (AssessmentItem $item): bool => $item->category === AssessmentItemCategory::ScholarshipDiscount);
            $adjustable = $items->reject(fn (AssessmentItem $item): bool => $item->category === AssessmentItemCategory::ScholarshipDiscount);
            $submitted = collect($validated['items'])->keyBy('id');

            if ($adjustable->count() !== $submitted->count() || $adjustable->keys()->sort()->values()->all() !== $submitted->keys()->sort()->values()->all()) {
                throw ValidationException::withMessages([
                    'items' => 'Submit every fee line in this assessment exactly once.',
                ]);
            }

            $before = $this->auditValues($assessment, $items);
            $total = '0.00';

            foreach ($adjustable as $item) {
                /** @var array{id: int, amount?: ?string, unit_amount?: ?string} $input */
                $input = $submitted->get($item->id);

                if ($item->category === AssessmentItemCategory::Tuition) {
                    $rate = $input['unit_amount'] ?? null;
                    if (! is_string($rate) || $item->quantity === null) {
                        throw ValidationException::withMessages([
                            "items.{$item->id}.unit_amount" => 'A tuition rate is required for every tuition line.',
                        ]);
                    }

                    $amount = $this->multiplyRounded($item->quantity, $rate);
                    $item->update(['unit_amount' => $rate, 'amount' => $amount]);
                } else {
                    $amount = $input['amount'] ?? null;
                    if (! is_string($amount)) {
                        throw ValidationException::withMessages([
                            "items.{$item->id}.amount" => 'An amount is required for every other fee line.',
                        ]);
                    }

                    $amount = bcadd($amount, '0', 2);
                    $item->update(['amount' => $amount]);
                }

                $total = bcadd($total, $amount, 2);
            }

            if ($discountItem instanceof AssessmentItem) {
                $tier = ScholarshipTier::fromStoredQuantity($discountItem->quantity);

                if ($tier === null) {
                    throw ValidationException::withMessages([
                        'items' => 'The scholarship on this assessment is not recognised. Remove it and apply it again.',
                    ]);
                }

                $discount = $tier->discountFor($total);
                $discountItem->update(['amount' => bcmul($discount, '-1', 2)]);
                $total = bcsub($total, $discount, 2);
            }

            $assessment->update(['total_amount' => $total]);
            $assessment->load('items');

            $this->auditRecorder->record(
                $actor,
                AuditAction::ASSESSMENT_ADJUSTED,
                AuditableType::ASSESSMENT,
                $assessment->id,
                $before,
                $this->auditValues($assessment, $assessment->items),
                $validated['reason'],
                $context,
            );

            return $lockedEnrollment->refresh()->load([
                'student.user', 'enrollmentSubjects.section.subject', 'queueTicket', 'assessment.items',
            ]);
        });
    }

    /** @param Collection<int, AssessmentItem> $items
     * @return array{total_amount: ?string, items: list<array{id: int, label: string, amount: ?string, unit_amount: ?string}>}
     */
    private function auditValues(Assessment $assessment, Collection $items): array
    {
        return [
            'total_amount' => $assessment->total_amount,
            'items' => $items->map(fn (AssessmentItem $item): array => [
                'id' => $item->id,
                'label' => $item->label,
                'amount' => $item->amount,
                'unit_amount' => $item->unit_amount,
            ])->values()->all(),
        ];
    }

    private function multiplyRounded(string $quantity, string $rate): string
    {
        return bcadd(bcmul($quantity, $rate, 4), '0.005', 2);
    }
}
