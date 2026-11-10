# ADR 0047 — Promissory Note Only for a Previous Bill, and a New Student Keeps the Category Admission Chose

**Status:** Accepted
**Date:** 2026-10-13
**Related:** ADR 0021 (term-scoped standing), ADR 0028 (year promotion and back subjects), ADR 0043 (freshman pay-first), ADR 0046 (full-payment discount).

## Context

Four problems came from the owner's review of the Cashier and the Student pages (stakeholder document of 2026-10-12):

1. The "Confirm received payment" modal opened on Pay in full with the whole balance filled in. Most students pay in instalments, and a pre-filled full balance is easy to confirm by accident.
2. A payment of 10,400 against an assessment of 10,500 still demanded a promissory note, for a student with nothing owed from before. The owner's rule: a promissory note is only for a student who has a bill from a previous semester. A downpayment of 1,000 on this term, with no previous bill, needs none.
3. The Student's Schedule page listed every school year back to 2017 in its picker, whether or not the student had any data in it.
4. A student created by Admission turned Irregular within seconds of opening the Enrollment page. A year-4 student with no grades and no earlier enrollment had every earlier subject read as "not passed" and every prerequisite as "not met", so `BuildEnrollmentAccessContext`'s self-heal wrote Irregular.

## Decisions

1. **Cashier modal defaults.** Installment is the default payment type and the amount starts at 0.00. Confirm stays disabled until an amount of at least PHP 1,000.00 is typed, and the minimum is only shown as an error once the Cashier has typed. An assessment that is collected in full (below the minimum, or fully covered by a scholarship) is still filled in, because nothing can be typed there. The shared `PaymentTypeChoice` is unchanged; the freshman and transferee first-payment dialog keeps its own defaults.
2. **Promissory note rule.** `PromissoryNoteRule` (one class, used by `ConfirmPaymentRequest` and `ConfirmPayment`): a note is required when the payment is partial (below this term's assessment) **and** the student has a previous bill, meaning an unpaid balance on another of their enrollments, read from `BuildStudentAccountBalance` (the same account view the Cashier and Student see). A student with no previous bill, a payment that clears the whole term, and a previous bill that is settled all need no note. The freshman and transferee first payment never needed one (ADR 0043) and is unchanged. The Cashier screen shows the checkbox only when the account has a previous bill (or when the server has just asked for one).
3. **Student term picker.** The Schedule page offers the current term and every term the student has an enrollment with subjects in. Nothing else is listed.
4. **A student with no record is not classified.** `ClassifyEnrollmentStanding` returns `null` (undetermined) for a student who has no locked result, no credited subject, no transferee credit on file (whatever its status) and no `enrolled` enrollment in another term. An enrollment in the term being classified is not a record: it is the enrollment the verdict is for. The category Admission chose then stands, in either direction, and nothing is written, audited or notified. This extends ADR 0028, where `null` meant only "no block yet". A student who has any record is derived exactly as before.

## Consequences

- A new student stays Regular or Irregular as Admission set it until grades, credits or an earlier enrollment give the system something to derive from. A year-4 student who is really behind is Irregular only if Admission says so, or once there is a record that shows it.
- A student who is already Irregular because of this bug is not changed back by the code: the category is corrected by Admission or the Registrar on the student's profile (allowed until the first enrollment).
- Test fixtures that build students with no record now give them one (an earlier-term result in a subject no curriculum places) or set the category explicitly, which is also what real students look like.
- No migration.

## Addendum (2026-10-13, later the same day)

The owner asked for everything that was left open to be fixed. Two of those items were code:

- **The freshman and transferee first-payment dialog also opens on Installment** (decision 1 above said it kept its own defaults). Its amount stays pre-filled with the PHP 1,000.00 minimum, which is the pay-first rule (ADR 0043), so only the payment type changed: the 10% discount is applied only when the Cashier picks Pay in full.
- **The Regular explanation on the Enrollment page** no longer says every earlier subject "has a passing, on-time grade", which is untrue for a student with no record (decision 4). It now says the student has no unresolved subject from the semesters already completed.

The presentation restore script (`backend/scripts/restore_presentation_term6.php`) also puts back what a rehearsal changes on rows that stay: a real student whose category was derived again after the term was archived gets the category from the audit trail (limit `--max-category-reverts`), the student-number counter gives back the numbers of the deleted test accounts, and the empty queue cycles the rehearsal opened are removed (the cycle its first claim closed is open again).
