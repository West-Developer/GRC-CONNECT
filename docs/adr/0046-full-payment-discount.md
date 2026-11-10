# ADR 0046 — Full-Payment Discount With a Registrar Head Override

**Status:** Accepted
**Date:** 2026-10-12
**Related:** ADR 0025 (scholarship discount as an assessment line), ADR 0043 (freshman and transferee pay-first). Spec: `docs/superpowers/specs/2026-10-11-full-payment-discount-design.md`.

## Context

The owner asked for a 10% discount for students who pay in full. An installment gets no discount and no extra fee. The percentage and its validity must be editable, and the control belongs to the Registrar Head because every Accounting user is staff and Accounting has no head. PRD FR-FIN-007 to FR-FIN-009 (payment confirmation, COM, idempotency) say nothing about discounts, and ADR 0025 had already established that a discount is a negative assessment line.

## Decisions

1. **"Full payment" is the whole net assessment collected in one Cashier payment.** Anything less is an installment, which has no discount and no extra fee. A request with no `payment_type` behaves exactly as before.
2. **The discount is a negative line** (`full_payment_discount`, percentage in `quantity`) written when the money is collected, never earlier, inside the transaction that collects it: `ConfirmPayment` for a returnee or continuing student, `SettleEnrollmentFromAdvanceCredit` at the Registrar's approval for a pay-first student (decision 7). The base is tuition plus miscellaneous (`ScholarshipBase`), the same as a scholarship. It is rounded half-up to the cent and audited as `assessment.full_payment_discount_applied`.
3. **No stacking.** An assessment with a scholarship line gets no full-payment discount (quote reason `scholar`).
4. **Settings:** one row, `payment_discount_settings` (`percentage` 0 to 100 with one decimal, `valid_from` and `valid_until` as inclusive `Asia/Manila` dates, either may be null). Created on first read at the configured default, 10.0 (`config('fees.full_payment_discount_percentage')`). A percentage of 0 turns the discount off (`off`), a window that has not started is `scheduled`, one that has passed is `expired`; only an `active` setting offers a discount.
5. **Who edits it:** the Registrar Head only (`PaymentDiscountSettingPolicy`), through a new "Payment Discount" module. Accounting Staff may read it. Every change is audited (`payment_discount.updated`) and a save that changes nothing writes no audit row. Editing never changes an assessment, payment or COR that already exists.
6. **Returnee / continuing students:** `GET /enrollments/{enrollment}/payment-quote` returns what a full payment collects: `total_amount` (the amount due before any discount) and a `full_payment` object (`applies`, `percentage`, `discount_amount`, `net_total`, `reason`). `net_total` is always what a full payment collects; when no discount applies `reason` is `off`, `scheduled`, `expired` or `scholar`. `POST /enrollments/{enrollment}/payment` with `payment_type: full` carries the `quoted_amount` the Cashier was shown and no `amount`; if the server's figure differs (the Head edited the setting, or a fee was corrected) the request is refused with 422 on `quoted_amount` and nothing is written. `payment_type: installment` carries an `amount` and no discount.
7. **Pay-first students (freshman, transferee):** their money is held as credit before any assessment exists. A full payment collects the discounted *estimate* (`PreviewFreshmanPayment`, `full_payment` on the preview; `amount` must equal its `net_total`, or 422), and the quoted percentage is frozen on `student_profiles.full_payment_discount_percentage` (only when a discount applied). With no estimate yet the preview says `no_estimate` and a full payment is refused. At Registrar approval, after the assessment is built, the discount line is written from the frozen percentage only if the held credit covers the discounted net of the *actual* assessment (and the assessment has no discount line already); otherwise the payment is treated as an installment. The percentage is cleared when the settlement runs, whether or not the discount was applied, so it can never reach a later term. The saved validity window is not rechecked at approval: the student was promised that percentage when they paid.
8. **Cashier UI:** Pay in full / Installment is offered only when a discount applies. Otherwise the modal is unchanged, so nothing about taking a payment depends on this feature being reachable.
9. **COR:** the snapshot gains `fees.full_payment_discount` and `fees.total_full_payment_discount`; `grand_total` is the net. Snapshots made earlier are untouched.

## Alternatives considered

- **Apply the discount when the assessment is built.** Rejected: the student may never pay in full, and ADR 0025's lesson is that the assessment must read the truth at every point.
- **Store the discount in `fee_schedules`.** Rejected: that table has no validity dates and is Accounting's.
- **A toggle that is always shown.** Rejected: with no discount on offer it does nothing and adds a step to every payment.
- **Honor the frozen percentage even when the credit is short.** Rejected: it would give a discount the money does not cover.

## Consequences

- Two new migrations: `2026_10_12_000001_create_payment_discount_settings_table` and `2026_10_12_000002_add_full_payment_discount_percentage_to_student_profiles_table`. Until they run, these return 500 (each list is exact; anything not listed is unaffected):
  - Without `..._000001`: `GET` and `PUT /api/v1/payment-discount-settings`; the Cashier's `GET /enrollments/{enrollment}/payment-quote` (unless the assessment already has a scholarship line, which is answered before the setting is read); a returnee's or continuing student's payment with `payment_type: full`; and the freshman and transferee payment preview, and their `payment_type: full` confirmation, once an estimate exists. A payment with no `payment_type`, a returnee installment and a freshman or transferee installment confirmation never read the setting.
  - Without `..._000002`: every freshman or transferee first-payment confirmation, a plain installment included, because `ConfirmFreshmanPayment` always writes `student_profiles.full_payment_discount_percentage`. The preview and a returnee's payment never touch that column.
- A student who pays the discounted amount and later withdraws or is voided keeps the discount on the paid assessment. The owner decided this on 2026-10-12: voiding ends the enrollment process only, the payment is not refunded and the student does not return to the Cashier, and GRC has no refund process (PRD §17).
- If GRC wants a different base or stacking rule, change `ApplyFullPaymentDiscount` and `QuoteFullPayment`; existing lines keep their stored amount and percentage.
- Tests: `FullPaymentDiscountPolicyTest`, `QuoteFullPaymentTest`, `PaymentDiscountSettingTest`, `PaymentDiscountSettingEndpointTest`, `FullPaymentDiscountEndpointTest`, `FullPaymentPayFirstTest`, `PaymentDiscountMigrationsTest`, `ApiSurfaceTest`.
