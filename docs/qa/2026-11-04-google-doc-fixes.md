# Google Docs fixes — verification record

The user reported these issues on the deployed system. Requirements and three
embedded screenshots came from Google Doc
`1oGiWwGQTbQfIyiwf5HKySGNLwDuE2vLTPia45FZwhfQ` on 2026-11-04.

## Changes

| Request | Result |
| --- | --- |
| Registrar-approved enrollment missing in Cashier | Added a paginated **Approved for payment** list with explicit **Issue queue ticket**. Approval still creates no ticket automatically. Waiting, serving and served tickets use separate status-specific pages, so 100 completed tickets cannot hide the active queue. Visible ticket enrollments are hydrated by bounded exact IDs. |
| Broken Admission dashboard | The screenshot showed the GRC Connect home. Its two workspace cards now fill the row and align their titles. Also fixed verified overlapping department status counters on Enrollment Dashboard. |
| Student creation email not received | Fixed false success feedback for creation and all Admission resend controls, same-second failed delivery status, and production mail sinks that previously counted discarded messages as sent. Added opt-in `resend_http` transport using the existing HTTP client, with redacted failures and no new dependencies. Live provider setup remains required. |
| Registrar Head student list descending | Server orders student numbers descending before pagination, with descending ID tie-breaking; search and Registrar authorization remain covered. |

Freshmen still receive their queue-link email at intake and account setup only
after their first payment. Other student types retain their existing setup flow.
The changes preserve Sanctum bearer authentication and role-scoped records.

## Original pre-deployment workspace checks

| Check | Result |
| --- | --- |
| Cashier applicable backend matrix | **188 tests / 692 assertions passed** on PHP 8.3.36 and isolated MariaDB. Covers enrollments, queue listing/claims/cycles/kiosk, payment candidates/confirmation, freshman payments/tickets and student queue views. |
| Registrar list and student-profile policy | **9 tests / 44 assertions passed**. Descending page ordering was first observed failing against the old ascending query. |
| Email applicable backend matrix | **86 tests / 426 assertions passed** on PHP 8.3.36. Covers HTTP transport, production sink guard, resource delivery status, account setup/resend, student profiles, freshman payment/intake, and rendered mail templates. One existing PHPUnit deprecation was reported. |
| Combined frontend regression | **9 files / 184 tests passed**: Cashier, Admission creation/freshmen, home/dashboard, Registrar students, and related services. Final Cashier copy version also passed its 73-test focused run. |
| Production build | **Passed**, including TypeScript and all 14 pages. Used `.next/qa-google-doc-20261104`; generated `tsconfig.json` and `next-env.d.ts` changes were restored byte-for-byte. |
| Responsive visual QA | **Passed** at 390, 768, 1024, 1280, 1440 and 1920 pixels: no clipped department controls, horizontal page overflow, unused third home-grid column, misaligned home titles, or JavaScript errors. Root inspected the final desktop and phone screenshots. |
| Changed-file quality checks | Scoped ESLint, Prettier, Pint, PHPStan and `git diff --check` passed. Final email PHPStan checked six source files with zero errors. |

Visual artifacts and before/after measurements are in
`%TEMP%/grc-qa-admission-dashboard`. The repeatable harness is
`frontend/scripts/qa-admission-dashboard.mjs`; it renders real components with
synthetic data and framework adapters, and never calls the live API.

## Deployment boundary

The table above records the original workspace checks before the user requested
deployment. After that request, a separate export of Git HEAD plus the reviewed
37-file patch was verified independently. Earlier unrelated enrollment QA edits,
private local files and database dumps were excluded from that deployment patch.

| Exact exported-source check | Result |
| --- | --- |
| Backend regression matrix | **272 distinct tests / 1,107 assertions passed**, with overlapping suites deduplicated, on PHP 8.3.36 and a private synthetic database. |
| Frontend regression | **9 files / 183 tests passed**. The unrelated earlier eligibility-revision test was deliberately excluded from this scoped source snapshot. |
| TypeScript and ESLint | **Passed**, including targeted ESLint on all 12 changed JavaScript/TypeScript files. |
| Production webpack build | **Passed**, with all 14 pages generated. Built client chunks use the current Render API origin; generated TypeScript configuration was restored. |
| Backend static analysis | **PHPStan passed on 10 application source files**. |
| Backend formatting | **Pint passed on 18 LF-normalized disposable copies** of the exported PHP files. The checked source export was not rewritten. |

The unified patch and canonical Git blobs use LF line endings. The Windows export
contains CRLF file bytes; their LF-normalized content is identical to the reviewed
source. Its manifest records the actual exported file hashes, without claiming
literal byte identity with Git blobs. Only the QA record and November 4 progress
ledger are refreshed after verification; the other 35 selected source files keep
their verified bytes.

Publication and live deployment verification are the next steps; no deployment
success or inbox arrival is claimed by these source checks. No live student
mutation, external email or production configuration change was made during
verification. The full repository release suite was not rerun; historical global
lint/release findings are not relabelled as passing.

Render Free blocks SMTP ports 25, 465 and 587. If the deployment retained Gmail
SMTP, that hosting restriction explains email failure; the deployed environment
was not inspected. Actual delivery needs an HTTPS provider, a sending key and
verified sender/domain, followed by provider and inbox verification. Follow
[Render email setup](../runbooks/render-email.md). Never put an API key in git or
chat. Provider acceptance alone does not prove inbox arrival.

Backend tests used separate synthetic schemas on the task-owned MariaDB at
`127.0.0.1:33317`, with its data directory under Temp. The original local database
and deployed TiDB database were not used by database-resetting tests. Portable
PHP 8.3.36 was downloaded from php.net and verified against the official SHA-256;
XAMPP PHP 8.2.12 was not replaced.

The original temporary MariaDB run was shut down after its test processes
completed; exact deployment-source verification used a separately restarted
task-owned instance and a private synthetic schema. The only
email-suite deprecation was the pre-existing doc-comment data provider on
`AccountLinkEmailsTest::test_the_link_is_a_block_button_and_is_repeated_as_a_plain_text_link`;
its migration to a PHPUnit attribute remains outside this fix.
