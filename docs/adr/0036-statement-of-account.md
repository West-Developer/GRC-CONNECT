# ADR 0036 — Statement of Account

**Status:** Accepted
**Date:** 2026-09-26
**Amends:** PRD §3.9 (Accounting Staff) and the Student portal scope.

## Context

Stakeholder Doc 14 asks for a Statement of Account for the Student and for Accounting. Before this change only a summary balance existed (`BuildStudentAccountBalance`, `GET /student-account`): current outstanding, prior balance, and a flat transaction list, with no per-term breakdown and nothing printable.

## Decisions

1. **Derived, not stored.** `BuildStatementOfAccount` reads assessments and payments only, like the balance, so the Student's view, the Cashier's view, and the printed copy always agree. It stores nothing and adds no migration.
2. **Shape.** Per term with an assessment (rejected and cancelled enrollments left out): the assessment lines (tuition, miscellaneous fees, scholarship discount), the payments (the enrollment payment with its promissory-note flag, and balance payments allocated to that term), what is still owed, the balance brought forward, and the running balance. Advance payments that belong to no term are listed as credits. Totals: assessed, paid, outstanding, advance on account. Formulas match the account balance.
3. **Term filter.** `academic_term_id` narrows which terms are listed; `prior_balance`, `running_balance`, and the totals still include every earlier term.
4. **Who.** The existing `StudentProfilePolicy::viewAccount` ability: a Student reads only their own (`GET /me/statement-of-account`), Accounting Staff read the served Student's (`GET /students/{student}/statement-of-account`). Every other role, including the Registrar Head, is refused.
5. **Print.** `.../pdf` variants render `pdf.statement-of-account` with DomPDF (the COR's approach), `Cache-Control: no-store, private`.

## Consequences

- The Student portal gets a "Statement of Account" module and Accounting Staff a tab next to the student lookup.
- Amounts are strings with two decimals (bcmath), as elsewhere in billing.