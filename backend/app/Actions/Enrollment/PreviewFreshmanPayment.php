<?php

namespace App\Actions\Enrollment;

use App\Actions\Billing\AssessEnrollment;
use App\Actions\Billing\QuoteFullPayment;
use App\Domain\Enrollment\EnrollmentBlock;
use App\Domain\Enrollment\EnrollmentCategory;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\FreshmanPaymentPreview;
use App\Domain\Identity\StudentType;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use Illuminate\Validation\ValidationException;

/**
 * Shows the Cashier what a freshman's first payment will do before it is confirmed
 * (ADR 0043): the first block with open seats — chosen by the same rule as
 * `ConfirmFreshmanPayment` — and the assessment it carries, or that every block is full.
 * Writes nothing.
 */
final readonly class PreviewFreshmanPayment
{
    public function __construct(
        private BuildEnrollmentBlockPool $blockPool,
        private AssessEnrollment $assessEnrollment,
        private QuoteFullPayment $quoteFullPayment,
    ) {}

    public function execute(StudentProfile $profile): FreshmanPaymentPreview
    {
        if (! $profile->student_type instanceof StudentType || ! $profile->student_type->isPayFirst()) {
            throw ValidationException::withMessages(['student' => 'Only a freshman\'s or transferee\'s first payment is confirmed this way.']);
        }

        $profile->loadMissing(['user', 'queueTicket']);
        $term = AcademicTerm::query()->where('status', AcademicTermStatus::SemesterOngoing)->first();
        $block = null;
        $enrollment = null;
        $estimatedTotal = null;

        if ($profile->student_type === StudentType::Transferee) {
            // A transferee chose their own subjects; they wait at the payment hold for this payment.
            $enrollment = Enrollment::query()
                ->where('student_id', $profile->id)
                ->where('status', EnrollmentStatus::AwaitingFirstPayment->value)
                ->with('enrollmentSubjects.section.subject')
                ->orderByDesc('id')
                ->first();
            $estimatedTotal = ($enrollment === null || $term === null)
                ? null
                : $this->assessEnrollment->estimateTotal($profile, $term, (float) $enrollment->total_units);
        } elseif ($term !== null && $profile->enrollment_category !== EnrollmentCategory::Irregular->value) {
            // An Irregular-category freshman is not put in a block; the payment is only held as credit.
            $block = $this->firstOpenBlock($profile, $term);
            $estimatedTotal = $block === null
                ? null
                : $this->assessEnrollment->estimateTotal($profile, $term, $block->totalUnits);
        }

        // `estimateTotal()` returns a bcmath total, so it is a numeric string.
        /** @var numeric-string|null $estimatedTotal */
        return new FreshmanPaymentPreview(
            $profile,
            $profile->queueTicket?->ticket_number,
            FreshmanPaymentRules::minimumAmount(),
            $profile->freshman_payment_confirmed_at !== null,
            $block,
            $estimatedTotal,
            $enrollment,
            $this->quoteFullPayment->forEstimate($estimatedTotal),
        );
    }

    private function firstOpenBlock(StudentProfile $profile, AcademicTerm $term): ?EnrollmentBlock
    {
        $blocks = array_values(array_filter(
            $this->blockPool->execute($profile, $term, ignoreEnrollmentWindow: true),
            fn (EnrollmentBlock $block): bool => $block->isSelectable && $block->seatsRemaining > 0,
        ));
        usort($blocks, fn (EnrollmentBlock $a, EnrollmentBlock $b): int => strnatcmp($a->blockCode, $b->blockCode));

        return $blocks[0] ?? null;
    }
}
