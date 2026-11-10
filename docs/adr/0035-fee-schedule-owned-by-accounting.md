# ADR 0035 — Fee Schedule Owned by Accounting Staff

**Status:** Accepted
**Date:** 2026-09-26
**Amends:** PRD §3.7 (Registrar Head), §3.9 (Accounting Staff), and the "Accounting permissions" note in §1 (Accounting's only operational writes were the serving number and payment confirmation).

## Context

Stakeholder Doc 14: Fee Settings belongs to Accounting, not the Registrar Head. Before this change (verified in code) only the Registrar Head could `PUT /fee-schedules`, both roles could read them, and the update wrote no audit record although it changes what every student is assessed.

## Decisions

1. **Who writes.** Accounting Staff only. `PUT /fee-schedules` (controller check and `UpdateFeeScheduleRequest::authorize`) allows `accounting_staff` and refuses the Registrar Head, Registrar Staff, and every other role. The frontend module `fee-settings` moves from the Registrar Head's navigation to Accounting Staff's.
2. **Who reads.** Unchanged: Registrar Head and Accounting Staff (`GET /fee-schedules`).
3. **Audit.** Every save that changes something writes one `fee_schedule.updated` record with before and after values: `tuition_rate_per_unit`, and one `misc_fee_<id>` entry per miscellaneous fee holding "label: amount" plus its program codes and whether it is inactive. Keys use the fee's id, never its label, so a label can never trip the audit payload's forbidden-word check. A save that changes nothing writes no record. The save stays one transaction.
4. **No change to assessment.** Existing assessments keep the amounts they were created with; only assessments created after a change use the new schedule (unchanged behaviour, stated here because the owner changed).

## Consequences

- The Registrar Head loses the Fee Settings screen and the write; nothing else about their billing visibility changes.
- Accounting Staff now has three operational writes (serving number, payment confirmation, fee schedule).