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
 * The Cashier's "Regular payee" choice (ADR 0025): drops any scholarship line
 * from the enrollment's assessment, restores the undiscounted total, and marks
 * the student a Payee. Idempotent: with no discount it only sets the
 * classification. Audited only when a line was actually removed.
 */
final readonly class RemoveScholarshipDiscount
{
    public function __construct(
        private PayableAssessmentLock $lock,
        private AuditRecorder $auditRecorder,
    ) {}

    public function execute(Enrollment $enrollment, User $actor, AuditRequestContext $context): Enrollment
    {
        return DB::transaction(function () use ($enrollment, $actor, $context): Enrollment {
            [$locked, $assessment] = $this->lock->execute($enrollment);

            $items = $assessment->items()->lockForUpdate()->get();
            $existing = $items->first(fn (AssessmentItem $item): bool => $item->category === AssessmentItemCategory::ScholarshipDiscount);
            $before = [
                'total_amount' => $assessment->total_amount,
                'percentage' => ScholarshipTier::fromStoredQuantity($existing?->quantity)?->value,
            ];

            $base = ScholarshipBase::amount($items);

            if ($existing instanceof AssessmentItem) {
                $existing->delete();
                $assessment->update(['total_amount' => $base]);

                $this->auditRecorder->record(
                    $actor,
                    AuditAction::ASSESSMENT_SCHOLARSHIP_REMOVED,
                    AuditableType::ASSESSMENT,
                    $assessment->id,
                    $before,
                    ['total_amount' => $base, 'percentage' => null],
                    null,
                    $context,
                );
            }

            $locked->student()->update(['financial_status' => FinancialStatus::Payee]);

            return $locked->refresh()->load([
                'student.user', 'enrollmentSubjects.section.subject', 'queueTicket', 'assessment.items',
            ]);
        });
    }
}
