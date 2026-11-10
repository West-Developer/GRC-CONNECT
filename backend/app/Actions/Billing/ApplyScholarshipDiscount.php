<?php

namespace App\Actions\Billing;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Billing\AssessmentItemCategory;
use App\Domain\Billing\ScholarshipBase;
use App\Domain\Billing\ScholarshipTier;
use App\Domain\Identity\FinancialStatus;
use App\Models\AssessmentItem;
use App\Models\Enrollment;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0025: assigns the Cashier's scholarship tier to one enrollment's
 * assessment as a single negative line ("Scholarship discount (40%)") worth
 * that share of the tuition plus miscellaneous fees, and marks the student a
 * Scholar. Re-applying replaces the line rather than adding another, so it is
 * idempotent and never compounds; the discount is always taken from the base
 * (the other lines), never from an already discounted total.
 */
final readonly class ApplyScholarshipDiscount
{
    public function __construct(
        private PayableAssessmentLock $lock,
        private AuditRecorder $auditRecorder,
    ) {}

    public function execute(Enrollment $enrollment, ScholarshipTier $tier, User $actor, AuditRequestContext $context): Enrollment
    {
        return DB::transaction(function () use ($enrollment, $tier, $actor, $context): Enrollment {
            [$locked, $assessment] = $this->lock->execute($enrollment);

            $items = $assessment->items()->lockForUpdate()->get();
            $existing = $items->first(fn (AssessmentItem $item): bool => $item->category === AssessmentItemCategory::ScholarshipDiscount);
            $before = [
                'total_amount' => $assessment->total_amount,
                'percentage' => ScholarshipTier::fromStoredQuantity($existing?->quantity)?->value,
            ];

            $base = ScholarshipBase::amount($items);

            $discount = $tier->discountFor($base);
            $attributes = [
                'category' => AssessmentItemCategory::ScholarshipDiscount,
                'label' => $tier->lineLabel(),
                'quantity' => $tier->storedQuantity(),
                'unit_amount' => null,
                'amount' => bcmul($discount, '-1', 2),
            ];

            if ($existing instanceof AssessmentItem) {
                $existing->update($attributes);
            } else {
                $assessment->items()->create($attributes);
            }

            $net = bcsub($base, $discount, 2);
            $assessment->update(['total_amount' => $net]);
            $locked->student()->update(['financial_status' => FinancialStatus::Scholar]);

            $this->auditRecorder->record(
                $actor,
                AuditAction::ASSESSMENT_SCHOLARSHIP_APPLIED,
                AuditableType::ASSESSMENT,
                $assessment->id,
                $before,
                ['total_amount' => $net, 'percentage' => $tier->value, 'discount_amount' => $discount],
                null,
                $context,
            );

            return $locked->refresh()->load([
                'student.user', 'enrollmentSubjects.section.subject', 'queueTicket', 'assessment.items',
            ]);
        });
    }
}
