<?php

/**
 * PRD §5.3 process 3.3 requires the system to "compute the approved
 * assessment"; PRD §17 lists "payment confirmation fields and supporting
 * reference requirements" as an open institutional decision, and nothing
 * in this file constitutes that approval. The values below are explicit
 * user direction given during this slice's planning (2026-08-05), not a
 * signed-off GRC decision. On 2026-08-25 the user supplied a GRC COR
 * reference whose 13.50 units / PHP 2,700.00 tuition establishes the
 * PHP 200.00 per-unit rate and whose PHP 4,700.00 other-fee total establishes
 * the line schedule below. Update this file, not application code, if GRC
 * later issues a different official schedule — see PRD §17/§18.
 *
 * `tuition_per_unit` is env-overridable (a single scalar). `miscellaneous`
 * is deliberately NOT env-overridable — a list of {label, amount} rows has
 * no safe flat-string env representation, the same reasoning already
 * applied to `enrollment.grading.special_marks` and
 * `enrollment.grading.completion_only_code_prefixes`. Edit the array
 * directly to change the fee schedule.
 *
 * Rounding policy: half-up at two decimal places. This is also
 * provisional — GRC has never specified half-up vs. banker's rounding vs.
 * round-to-nearest-peso. See App\Domain\Billing\AssessmentComputation.
 */
return [
    'currency' => env('FEES_CURRENCY', 'PHP'),

    'tuition_per_unit' => env('FEES_TUITION_PER_UNIT', '200.00'),

    /**
     * ADR 0046: the starting full-payment discount, used only when the
     * `payment_discount_settings` row does not exist yet. After that, the percentage the
     * Registrar Head saved is what counts. One decimal place at most.
     */
    'full_payment_discount_percentage' => env('FEES_FULL_PAYMENT_DISCOUNT_PERCENTAGE', '10.0'),

    /**
     * `program_codes` means the fee applies only to that program; omitted
     * means it applies to every student. Computer Lab Fee 2 is explicitly
     * labelled BSIT in the approved reference, so it must not be charged to
     * other programs automatically.
     *
     * @var list<array{label: string, amount: string, program_codes?: list<string>}>
     */
    'miscellaneous' => [
        ['label' => 'Registration', 'amount' => '200.00'],
        ['label' => 'Guidance and Counseling and Student Affair', 'amount' => '200.00'],
        ['label' => 'Medical and Dental', 'amount' => '200.00'],
        ['label' => 'Student Information System Fee', 'amount' => '200.00'],
        ['label' => 'Energy/Water/Communication Fees', 'amount' => '1200.00'],
        ['label' => 'Community Extension Fee', 'amount' => '200.00'],
        ['label' => 'Research & Publication', 'amount' => '200.00'],
        ['label' => 'Computer Lab Fee 1 (All Students)', 'amount' => '500.00'],
        ['label' => 'Student I.D.', 'amount' => '100.00'],
        ['label' => 'Development Fee', 'amount' => '400.00'],
        ['label' => 'Postal', 'amount' => '150.00'],
        ['label' => 'Computer Lab Fee 2 (BSIT)', 'amount' => '500.00', 'program_codes' => ['BSIT']],
        ['label' => 'Sports Development Fee', 'amount' => '50.00'],
        ['label' => 'Hand Book', 'amount' => '100.00'],
        ['label' => 'Library Fee', 'amount' => '500.00'],
    ],
];
