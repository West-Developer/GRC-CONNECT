<?php

namespace App\Actions\Billing;

use App\Domain\Billing\AssessmentComputation;
use App\Domain\Billing\ComputedAssessment;
use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\AssessmentItem;
use App\Models\Enrollment;
use App\Models\FeeSchedule;
use App\Models\StudentProfile;
use RuntimeException;

/**
 * Computes and records what an enrollment was assessed to owe (PRD §5.3
 * process 3.3), reading `config('fees.*')` and handing primitives to the
 * pure `App\Domain\Billing\AssessmentComputation` — this Action is the
 * framework boundary that domain deliberately stays free of, the same
 * split `App\Domain\Academic\PrerequisiteEvaluator` draws against its own
 * caller.
 *
 * Called from exactly one place: `App\Actions\Enrollment\TransitionEnrollment`'s
 * `registrar_approve` branch, in the same transaction as the queue ticket
 * — never called standalone. Idempotent via `assessments.enrollment_id`'s
 * unique constraint (check-first, same pattern as `App\Actions\Enrollment\
 * ConfirmPayment`), though in practice `TransitionEnrollment`'s own
 * `REQUIRED_CURRENT_STATUS` guard already makes a second `registrar_approve`
 * on the same enrollment unreachable — this check is defensive, not a path
 * this slice's tests need to force open.
 *
 * An assessment is never recomputed once created — not on a later `void`
 * (which orphans it exactly the way `void` already orphans the enrollment's
 * `QueueTicket`, per that migration's own docblock), and not on a
 * post-payment add/drop (`App\Actions\Enrollment\TransitionEnrollmentChangeRequest`
 * never updates `enrollments.total_units`, so there is no reassessment/
 * adjustment-billing policy here — a PRD §17 gap, not an oversight of this
 * slice).
 */
final readonly class AssessEnrollment
{
    public function execute(Enrollment $enrollment): Assessment
    {
        $existing = Assessment::query()
            ->where('enrollment_id', $enrollment->id)
            ->with('items')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $computed = $this->compute(
            $enrollment->student()->firstOrFail(),
            $enrollment->academicTerm()->firstOrFail(),
            $enrollment->total_units,
        );

        $assessment = Assessment::create([
            'enrollment_id' => $enrollment->id,
            'total_amount' => $computed->totalAmount,
            'currency' => (string) config('fees.currency', 'PHP'),
        ]);

        foreach ($computed->lines as $line) {
            AssessmentItem::create([
                'assessment_id' => $assessment->id,
                'category' => $line->category,
                'label' => $line->label,
                'quantity' => $line->quantity,
                'unit_amount' => $line->unitAmount,
                'amount' => $line->amount,
            ]);
        }

        return $assessment->load('items');
    }

    /**
     * What an enrollment of `$totalUnits` units would be assessed to owe, without recording
     * anything — the Cashier's preview of a freshman's first payment (ADR 0043). It is the
     * same computation `execute()` stores, so the preview and the assessment never disagree.
     */
    public function estimateTotal(StudentProfile $student, AcademicTerm $term, float $totalUnits): string
    {
        return $this->compute($student, $term, $totalUnits)->totalAmount;
    }

    private function compute(StudentProfile $student, AcademicTerm $term, float $totalUnits): ComputedAssessment
    {
        return AssessmentComputation::compute(
            number_format($totalUnits, 1, '.', ''),
            $this->resolveTuitionPerUnit(),
            $this->miscellaneousFees($student, $term),
        );
    }

    /**
     * @return numeric-string
     */
    private function resolveTuitionPerUnit(): string
    {
        // Tuition stays one global per-unit rate — only miscellaneous fees can be scoped to a
        // semester (the sync in FeeScheduleController never writes a semester on the tuition row).
        $activeTuition = FeeSchedule::query()
            ->where('category', 'tuition')
            ->where('is_active', true)
            ->first();

        if ($activeTuition !== null && is_numeric($activeTuition->amount)) {
            return (string) $activeTuition->amount;
        }

        $key = 'fees.tuition_per_unit';
        $raw = config($key);
        $value = is_scalar($raw) ? (string) $raw : '';

        if (! is_numeric($value)) {
            throw new RuntimeException("Config value '{$key}' must be numeric; got ".var_export($raw, true).'.');
        }

        return $value;
    }

    /**
     * @return list<array{label: string, amount: numeric-string}>
     */
    private function miscellaneousFees(StudentProfile $student, AcademicTerm $term): array
    {
        $programCode = $student->program()->value('code');
        $semester = $term->semester;
        $fees = [];

        $dbFees = FeeSchedule::query()
            ->where('category', 'miscellaneous')
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('semester')->orWhere('semester', $semester))
            ->orderBy('sort_order')
            ->get();

        if ($dbFees->isNotEmpty()) {
            foreach ($dbFees as $entry) {
                $programCodes = $entry->program_codes;
                if (is_array($programCodes) && ! in_array('ALL', $programCodes, true) && ! in_array($programCode, $programCodes, true)) {
                    continue;
                }

                $fees[] = ['label' => $entry->label, 'amount' => (string) $entry->amount];
            }

            return $fees;
        }

        $raw = config('fees.miscellaneous');
        if (! is_array($raw)) {
            return $fees;
        }

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $label = $entry['label'] ?? null;
            $amount = $entry['amount'] ?? null;

            if (! is_string($label) || ! is_string($amount) || ! is_numeric($amount)) {
                continue;
            }

            $programCodes = $entry['program_codes'] ?? null;
            if (is_array($programCodes) && ! in_array($programCode, $programCodes, true)) {
                continue;
            }

            $fees[] = ['label' => $label, 'amount' => $amount];
        }

        return $fees;
    }
}
