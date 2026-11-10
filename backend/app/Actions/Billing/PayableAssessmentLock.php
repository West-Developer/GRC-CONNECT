<?php

namespace App\Actions\Billing;

use App\Domain\Enrollment\EnrollmentStatus;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\Payment;
use Illuminate\Validation\ValidationException;

/**
 * The gate every pre-payment change to an assessment goes through (the same
 * rule `AdjustEnrollmentAssessment` applies): the enrollment must still be
 * `pending_payment` with no confirmed payment, and both rows are locked so a
 * concurrent payment confirmation cannot slip between the check and the write.
 * Call it inside a database transaction.
 */
final readonly class PayableAssessmentLock
{
    /**
     * @return array{0: Enrollment, 1: Assessment}
     *
     * @throws ValidationException
     */
    public function execute(Enrollment $enrollment): array
    {
        $locked = Enrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== EnrollmentStatus::PendingPayment) {
            throw ValidationException::withMessages([
                'enrollment' => 'A scholarship can only be changed while the enrollment is pending payment.',
            ]);
        }

        if (Payment::query()->where('enrollment_id', $locked->id)->exists()) {
            throw ValidationException::withMessages([
                'enrollment' => 'A scholarship cannot be changed after payment confirmation.',
            ]);
        }

        $assessment = Assessment::query()->where('enrollment_id', $locked->id)->lockForUpdate()->first();

        if (! $assessment instanceof Assessment) {
            throw ValidationException::withMessages([
                'assessment' => 'This enrollment does not have an assessment to apply a scholarship to.',
            ]);
        }

        return [$locked, $assessment];
    }
}
