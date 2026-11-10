<?php

/**
 * PRD §17 lists the official passing-grade rule (including special marks and
 * equivalent grades) and the maximum regular-unit/overload approval workflow
 * as open institutional decisions requiring formal GRC approval. Nothing in
 * this file constitutes that approval.
 *
 * The grading values below are explicit user direction given during Phase 6
 * planning (2026-07-30), not yet a signed-off GRC decision: 3.00 is the
 * lowest passing mark on GRC's descending numeric scale (1.00 highest —
 * 5.00 failed), and INC ("Incomplete") / NC ("Not Complete") are recognised
 * special marks, notably on Leadership subjects. They are pre-populated here
 * so the system is demonstrable end to end, but every value is
 * environment-overridable, and — critically —
 * App\Domain\Academic\PrerequisiteEvaluator degrades to an explicit
 * "needs_verification" advisory, never a silent pass or fail, whenever
 * `grading.comparison` resolves to null. Update this file, not application
 * code, once GRC formally confirms the policy — see PRD §17/§18.
 */
return [
    'grading' => [
        // 'lower_is_better' (GRC's 1.00-5.00 scale) or 'higher_is_better'.
        // Null disables automatic comparison entirely.
        'comparison' => env('ENROLLMENT_GRADE_COMPARISON', 'lower_is_better'),

        // Used only to classify a *raw* recorded grade as passed/failed for
        // FR-ENR-003's "exclude completed subjects" check. Per-prerequisite
        // thresholds come from subject_prerequisites.minimum_grade instead,
        // set by the Program Chair per curriculum edge.
        'passing_grade' => env('ENROLLMENT_PASSING_GRADE', '3.00'),
        'failing_grade' => env('ENROLLMENT_FAILING_GRADE', '5.00'),

        // Grades that are neither numeric passes nor fails. Never treated as
        // satisfying a prerequisite. 'DRP' (Dropped) joins this list so the
        // existing PrerequisiteEvaluator special-marks branch handles it
        // without a code change — App\Domain\Academic\GradeMark is the
        // authoritative vocabulary these values are drawn from.
        'special_marks' => ['INC', 'NC', 'DRP'],

        // Subjects whose code matches one of these prefixes (after
        // App\Domain\Academic\CompletionOnlySubjectRule::normalizeCode()) are
        // graded Complete (C) / Not Complete (NC) only, never numerically —
        // Leadership (LEAD 1-8) per user direction (2026-08-04). Amending
        // which subjects are completion-only never requires a code change.
        'completion_only_code_prefixes' => ['LEAD'],
    ],

    // FR-ENR-004: maximum regular units and the overload approval workflow.
    // Standard defaults: 24.0 regular unit limit; up to 30.0 units overload
    // requiring Dean/Registrar approval; >30.0 units strictly rejected.
    'max_regular_units' => env('ENROLLMENT_MAX_REGULAR_UNITS', 24.0),
    'overload_max_units' => env('ENROLLMENT_OVERLOAD_MAX_UNITS', 30.0),

    // FR-FIN-004 / PRD §17: "Enrollment reservation timeout and seat-release
    // rules" is an open institutional decision — seats have been reserved
    // immediately and permanently on submission since Phase 6. Defaulting
    // this to true (rather than leaving it unenforced like the caps above)
    // is a deliberate choice: leaving it off would permanently inflate
    // `sections.enrolled_count` for every approved withdrawal, wrongly
    // blocking other students from an actually-open section. Idempotency
    // (never decrementing a section more than once per withdrawal) is
    // enforced unconditionally in App\Actions\Enrollment\TransitionWithdrawalRequest,
    // independent of this flag — only whether the release happens at all is
    // gated here.
    'withdrawal' => [
        'releases_seats' => (bool) env('ENROLLMENT_WITHDRAWAL_RELEASES_SEATS', true),
    ],

    // Phase 7c / Dean's "stuck-student reports" (PRD §3.5, FR-ANL-003). The
    // PRD never defines what makes an enrollment "stuck" — unlike the other
    // §17 entries this file flags, that question was never even registered
    // as an open institutional decision. Null (the default) means no
    // threshold is confirmed: the stuck-students view still shows every
    // non-terminal enrollment's dwell time in its current status (that part
    // is arithmetic, not policy), but nothing is labeled "stuck" until GRC
    // sets a value here — the same mechanism-implemented/value-flagged
    // pattern as max_regular_units above.
    'dashboard' => [
        'stuck_threshold_days' => env('DASHBOARD_STUCK_THRESHOLD_DAYS'),
    ],

    // ADR 0043: a freshman pays the Cashier first. The minimum is the same PHP 1,000
    // that every first enrollment payment already has (`ConfirmPaymentRequest`); a
    // freshman needs no promissory note. Environment-overridable, like every value here.
    'freshman_payment' => [
        'minimum_amount' => env('ENROLLMENT_FRESHMAN_MINIMUM_PAYMENT', '1000.00'),
    ],

    // Phase: queue kiosk claim/carry-over/cut-off. The physical
    // front-desk's "today" for queue_tickets.queue_date and the cycle
    // drain/reset rule only — deliberately NOT config('app.timezone')
    // (stays UTC everywhere else). See ADR: docs/superpowers/specs/
    // 2026-08-23-queue-kiosk-claim-carryover-cutoff-design.md.
    'queue' => [
        'timezone' => env('ENROLLMENT_QUEUE_TIMEZONE', 'Asia/Manila'),
    ],
];
