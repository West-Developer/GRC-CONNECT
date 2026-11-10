# ADR 0052 — Admission's Freshmen View: Who Has Paid, Where They Are, and Resending the Setup Email

**Status:** Accepted
**Date:** 2026-10-16
**Amends:** ADR 0043 (freshman pay-first enrollment): adds a read view for Admission; the payment gate on the setup email is unchanged.

## Context

A freshman's account setup email is sent only after the Cashier confirms the first payment (ADR 0043). Students' mailboxes are often full, so the email goes missing; the student frees some space and comes back to Admission to ask for it again. Admission had a "Resend email" button in the Student Directory, but no way to see, for the freshmen they processed, who has paid, where each one stands, and whether the email went out, so they could not tell when a resend was due.

## Decisions

1. **One more tab, no new endpoint.** Student Records gets a **Freshmen** tab. It reads the existing `GET /api/v1/student-profiles` (Admission Staff only, `viewAny`), which now accepts three optional filters: `student_type` (a `StudentType` value) and `account_setup_status` (`awaiting_payment`, `pending` or `active`). Nothing new is exposed: every field on a row was already in the directory. An unknown value is a 422.
2. **Only the freshmen Admission is processing.** A third filter, `intake=pay_first`, keeps the students who hold a queue number or whose first payment the Cashier confirmed, newest first. Without it the view would be useless: on production 3,000 of the 3,002 students typed "freshman" are imported historical records (`HIST-…`: graduated, withdrawn or enrolled in past years, disabled accounts, no payment, no queue number), and every one of them would read "Awaiting payment" ahead of the real freshmen. This is a deliberate filter, not an inference from the `HIST-` prefix, so a pre-queue-number freshman who has paid still shows.
3. **The filter means exactly what the row's badge says.** `account_setup_status` uses the same three states as `StudentProfileResource`: `active` (the student set the account up), `awaiting_payment` (a freshman the Cashier has not confirmed), `pending` (everyone else still to set up). The `pending` filter is written out with the NULL cases rather than as `NOT (freshman AND unpaid)`, which SQL evaluates to NULL for an older profile with no student type and would silently drop it.
4. **What a row shows.** Name, student number and email; queue number; **Payment** (Not paid yet, or Paid with the date in Asia/Manila time); **Stage** (waiting for the Cashier, paid and waiting for a section, paid and enrolling); **Setup email** (the existing status badge: awaiting payment, pending delivery, awaiting setup, delivery failed, activated). Filters: All freshmen, Awaiting payment, Paid · account not set up, Account set up; plus a search by name, student number or email.
5. **When a resend is offered.** **Resend setup email** appears only for a freshman who has paid and not yet set the account up: exactly what `SendStudentAccountSetupInvitation` accepts. A freshman who has not paid gets **Resend queue link** and the line "The setup email is sent after the Cashier confirms the payment"; an activated account says so. The server rules are untouched, so a resend for the wrong student is still refused there.
6. **A failed send is not reported as a success.** The resend endpoint answers 200 with `invitation_delivery_status: "failed"` when the mail server rejects the message (it also records the failure and the audit row). The new button shows an error naming the address in that case, and a success only when the status is not `failed`.
7. **Same record dialog.** Clicking a name opens the Student Directory's record dialog, where Admission can correct a wrong email address before resending.

## Consequences

- Admission can see at a glance which paid freshmen have not set up their account, and resend the email to them. Each resend issues a fresh setup code, as before; there is no new rate limit (Admission is the only caller, and every send is audited).
- No migration, no new route, no new permission. The directory's default (no filter) answer is unchanged.
- The older directory's "Resend email" button still reports a success for a failed delivery; it is left as it was.
