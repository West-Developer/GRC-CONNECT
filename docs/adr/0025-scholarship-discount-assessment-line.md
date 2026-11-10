# ADR 0025 — Scholarship Discount as an Assessment Line, and the Cashier's Payee/Scholar Step

**Status:** Accepted
**Date:** 2026-09-24
**Supersedes:** the "informational only, does not change any fee computation" note on `FinancialStatus` (ADR 0022 era). The wider scholarship-waiver policy stays a PRD §17 open item.

## Context

Stakeholder feedback (Google Doc "DANHIL", 2026-09-24) asked the Accounting Staff's
**Confirm payment** step to start with a **Regular payee or Scholar** question. A
Regular payee goes on to the payment modal, which must enforce the first-payment
minimum of ₱1,000. A Scholar gets a second modal to pick a **100%, 40% or 20%**
classification and the discount is **deducted automatically**. The Assign
Scholarship Discount action belongs in that flow.

Before this, a scholarship was two loose things: `financial_status` (a label on
the student that never touched any amount) and the Advance Payment page, which
booked a "discount" as a **credit payment** (`RecordAccountPayment`). Neither
reduced the enrollment's assessment, so Amount due, the payment modal and the
COR still showed the full fees. `AssessmentItemCategory` also said that a
discount category is "a deliberate PRD §17 decision, not something to bolt on
silently". This ADR is that decision. PRD FR-FIN-005 to FR-FIN-009 (queue,
payment confirmation, COM, idempotency) say nothing about scholarships.

## Decisions

**1. A scholarship is a negative line on the assessment.** New
`AssessmentItemCategory::ScholarshipDiscount` (`scholarship_discount`). Applying
tier *N* writes one line, `Scholarship discount (N%)`, with a negative `amount`,
and sets `assessments.total_amount` to the net. Amount due, the Confirm payment
modal, the student's balance and the COR all read that total, so they agree.
`assessment_items.category` is a string column and `amount` is a signed
`decimal(10,2)`, so no migration is needed.

**2. Three tiers, chosen from a list, never typed.** `ScholarshipTier`: 100
(Full Academic Scholarship), 40 and 20 (Partial). The request validates the
percentage against exactly those values. The Advance Payment page's "custom
scholarship amount" option is dropped.

**3. The percentage applies to the whole assessment** (tuition plus miscellaneous
fees), the same base the Advance Payment page used. The discount is always taken
from that base (`ScholarshipBase`), never from an already discounted total, so
re-applying or changing a tier cannot compound. It is rounded half-up to the cent
like every other assessment amount (`bcadd(…, '0.005', 2)`).

**4. The tier lives on its line.** It is stored in the line's `quantity`
(`decimal(6,1)`, e.g. `40.0`), so `AdjustEnrollmentAssessment` can recompute the
discount when the Cashier corrects a fee, without a new column. The discount line
is derived, never edited by hand: it is left out of the lines the Cashier submits
and rejected if submitted.

**5. When it may change.** Only while the enrollment is `pending_payment` and has
no confirmed `Payment` row (`PayableAssessmentLock`, the same rule and row locks as
the fee adjustment), and only by Accounting Staff (`EnrollmentPolicy::adjustAssessment`).
After payment the COR is immutable, so the discount is too.

**6. Endpoints.** `PUT /api/v1/enrollments/{enrollment}/scholarship-discount`
(`percentage` ∈ 100|40|20) applies or replaces; `DELETE` on the same path is the
Regular payee choice and removes it. Both are idempotent, return the updated
enrollment, and set the student's `financial_status` (scholar / payee). Applying
and removing are audited (`assessment.scholarship_applied`,
`assessment.scholarship_removed`); removing with no discount writes no audit row.

**7. Payment confirmation and the COR.** `ConfirmPayment` is unchanged. The
₱1,000 minimum already lives in `ConfirmPaymentRequest` (`amount` `min:1000`) and
applies to an explicit amount only; an omitted amount falls back to the assessment
total. So a **100% scholar owes ₱0** and confirms with no amount (a `Payment` of
`0.00`, the COR is still generated), and a net **below ₱1,000** is paid in full by
omitting the amount. `BuildCorSnapshot` adds `fees.scholarship_discount` (the
lines) and `fees.total_scholarship_discount`; `grand_total` is already the net, and
tuition + other fees + the discount add up to it. The printed COR template lists
the discount above the grand total. Snapshots generated before this change have no
such fields and are untouched.

**8. The Cashier's flow.** Confirm payment opens *Payment classification*
(Regular payee / Scholar, preselected from the student's classification). Regular
payee removes any discount and continues to the payment modal, which shows the
₱1,000 minimum. Scholar opens *Scholarship classification* (100 / 40 / 20 with a
live Current assessment / Discount / Net payable summary); applying it refreshes
the assessment and continues to the payment modal at the net amount. "Assign
Scholarship Discount" moves here from the Advance Payment page, which keeps Record
Payee Advance Payment and Edit Classification.

## Alternatives considered

- **Keep booking the discount as a credit payment.** No assessment change, but the
  discount then looks like money received (it gets a reference number, inflates
  "Total paid"), Amount due and the COR total stay full, and it needs a base the
  cashier can only see in one place. Rejected.
- **Percentage of tuition only.** Offered to the stakeholder; the whole-assessment
  base was chosen because it matches how the Advance Payment page already computed
  it.
- **A `scholarship_percentage` column on `assessments`.** Cleaner to read but a
  migration, and this repo's migration step-count tests go stale whenever one lands.
  Storing the tier in the line's `quantity` avoids it.

## Consequences

- A student's classification can change in two places (Edit Classification on the
  Advance Payment page, and this flow). Setting Scholar there does not add a
  discount; only this flow does.
- The student-facing payment panel lists the discount as a negative line.
- If GRC later defines tiers or bases differently, change `ScholarshipTier` and
  `ScholarshipBase`; existing lines keep their stored amount and tier.
- Tests: `ScholarshipDiscountEndpointTest`, `ScholarshipTierTest`, the payment
  workspace tests, and `ApiSurfaceTest` (two new routes).
