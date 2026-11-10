# Google Docs fixes — 2026-11-04

Source: user-supplied Google Doc `1oGiWwGQTbQfIyiwf5HKySGNLwDuE2vLTPia45FZwhfQ`, read via its plain-text export on 2026-11-04.

## Requested behavior

1. Cashier can find enrollments approved by Registrar Staff.
2. Admission dashboard layout works again.
3. Student account creation emails arrive, with accurate delivery feedback.
4. Registrar Head's Students list uses descending order.

## Implementation and verification

- Preserve all existing uncommitted work on main. Read PRD and full progress history before editing (completed by the team).
- Cashier: reproduce missing approved enrollments and queue pagination loss; expose approved enrollments without automatically claiming queue tickets, preserve on-behalf ticket issuance, and test the operational queue and payment candidate flow.
- Admission: trace the dashboard layout and correct the smallest evidenced defect; verify desktop/mobile layout and relevant component behavior.
- Email: trace provisioning and synchronous delivery, preserve freshman pay-first invitation rules, correct any false success feedback, and verify configured transport behavior without sending messages to real recipients. Record any deployment configuration required for actual mailbox delivery.
- Students: reverse server-side student-number ordering before pagination, with deterministic descending ID tie-breaking; verify page boundaries, search, and Registrar access restrictions.
- Use synthetic isolated database tests only, targeted frontend tests, TypeScript, changed-file lint/format, and applicable regressions. Record actual results and limits in PROGRESS.md. No commit, push, deployment, live account changes, or live-data repair is requested.
