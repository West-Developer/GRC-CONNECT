# QA remediation — tala ng pagpapatupad at verification

## Saklaw

Batayan: `docs/superpowers/plans/2026-10-28-grc-enrollment-qa-remediation.md`. Kasama rito ang umiiral na uncommitted audit fixes at ang mga karagdagang pag-aayos noong Oktubre 28. Hindi awtomatikong inaaprubahan ang subject proposals. Walang commit, push, deployment, o data repair na isinagawa.

## Pinakabagong update — restoration counter at bagong test database

Naayos na sa kasunod na QA slice ang dalawang natitirang backend issue mula sa unang full run:

- **Student-number counter:** Ibinabalik lamang ang sunod-sunod na top suffixes na hawak ng deleted test accounts. Humihinto sa unrelated gap at hindi ibinababa ang counter kung may natitirang student na gumagamit ng suffix sa parehong taon, kahit ibang buwan. Nananatiling buo ang dry-run rollback at repeated execution.
- **Fresh-schema tests:** Gumagamit na ng `RefreshDatabase` ang `EvaluateCreditMappingStatusTest`, kaya naghahanda ito ng sariling schema at hindi umaasa sa migrations ng naunang suite.

Na-reproduce muna ang failures: 15 credit-mapping tests sa verified empty schema, at 4 failing counter cases. Pagkatapos ng fixes, pumasa ang credit-mapping class sa **15 tests / 48 assertions**, at ang counter subset sa **5 tests / 17 assertions**.

**Final applicable regression: PASS — 77 tests / 324 assertions**, kasama ang buong academic-action, identity-action at script suites, pati student-review class:

```powershell
php artisan test --compact tests/Unit/Actions/Academic tests/Feature/Actions/Identity tests/Feature/Scripts tests/Feature/Api/V1/EnrollmentStudentReviewTest.php
```

Pumasa rin ang targeted Pint, PHP syntax check at `git diff --check`. Synthetic records lamang sa temporary MariaDB ang ginamit. Hindi pinatakbo ang restoration sa aktuwal na records. Hindi muling itinakbo ang buong repository suite sa slice na ito; ang 25 failures sa unang full run sa ibaba ay historical evidence, hindi kasalukuyang failing count. Nananatiling bukas ang global lint at live end-to-end gates.

## Kasalukuyang status ng limang feature

Ang `PASS` ay para sa sinuring code at mga test na nakatala rito. Hindi ito awtomatikong release approval para sa buong sistema.

| Feature | Status | Simpleng paliwanag |
| --- | --- | --- |
| Counter-proposal ng irregular student | **PASS** | May owner/status/term checks, eligible choices, validation sa backend, tamang revision history, at seat-race/retry coverage. Kailangan pa rin ang desisyon ng Chair. |
| Dropped subjects sa aktibong schedule | **PASS** | Hindi kasama ang dropped subjects sa sinuring active views; may restoration at replay regressions. Nananatili ang historical data sa terminal enrollments ayon sa resource contract. |
| Table at calendar ng revised schedule | **PASS** | Pumasa ang component tests at desktop/mobile rendered checks; may malinaw na mensahe kapag kulang ang schedule. |
| 12-hour AM/PM display | **PASS** | Pumasa ang formatter at kaugnay na screen tests; naitama ang dalawang lumang assertions na naghahanap ng 24-hour text. |
| Calendar fallback kapag kulang ang reference data | **PASS** | May fallback sa orihinal na section; ang tahasang cleared/null na bagong schedule ay hindi pinapalitan ng lumang oras. May passing regression. |
| Restoration counter | **PASS** | Naayos ang counter mismatch at surviving-suffix protection; pumasa ang buong script suite bilang bahagi ng 77-test regression. Walang aktuwal na data restoration na ginawa. |
| Buong sistema / release readiness | **WARN** | May global lint at full-suite failures at wala pang live browser → API → database verification. Hindi pa kumpletong verified ang lahat ng roles, integrations, at ML evaluation. |

## Detalye ng mga pagbabago

| Bahagi | Ginawa | Ebidensya sa kasalukuyang session |
| --- | --- | --- |
| Revision choices | Optional enrollment context sa eligibility API; sariling irregular enrollment lamang sa tamang status at term; hiwalay na query key | Dalawang bagong backend tests: 11 assertions; eligibility at revision suites: 58 tests / 176 assertions |
| Selector | Eligible sections, dahilan sa hindi available, held-section fallback at snapshots; lecture/lab selection guidance | Final revision/service suites: 39 passed tests; natapos ang independent review at regression fixes |
| Seat safety | Prerequisite/waiver at dropped-subject replay coverage; bounded transaction retry | Dalawang bagong tests: 14 assertions; two-process last-seat race: 1 test / 5 assertions. Ang race ay unang naglabas ng deadlock at pumasa pagkatapos ng retry fix |
| Lumang revisions | Read-only `enrollments:audit-revisions --limit=200`; internal IDs at issue codes lang, walang private notes | Synthetic test: 10 assertions; aktuwal na configured local database: 0 pending checked, 0 findings, walang binago |
| Unknown author | Neutral na status kapag deleted o hindi matukoy ang proposer | Bagong UI regression: unang fail, pagkatapos pass |
| Policy display | Inalis ang invented na 24-unit limit sa student at Chair proposal screens | Bagong Chair regression: unang fail, pagkatapos pass |
| Static analysis | Inayos ang controller query typing at unnecessary curriculum nullsafe operators; required ang curriculum FK | Targeted PHPStan, targeted ESLint at final TypeScript: passed |

## Independent review at karagdagang fixes

May independent read-only reviewer na nakakita ng tatlong Important issues. Na-reproduce at naayos ang lahat sa isang fix pass: equivalent na course sa ibang college na puwedeng madoble, reserved na full section na hindi maibalik sa draft, at cleared schedule fields na napapalitan ng lumang oras. Pumasa ang mga regression tests. May dagdag na passing test para sa pag-restore ng lecture/lab pair at pagkakaiba ng courses na pareho ang code pero magkaiba ang units.

Na-reproduce rin ang Escape na nagsasara ng dialog habang pending ang submit. Pinipigilan na ang pag-dismiss habang hindi pa tapos ang request; pumasa ang regression nito. Hindi na rin nagsasabing Within Regular Load kapag hindi alam ang units.

Pumasa ang applicable backend matrix sa 232 tests / 885 assertions. Ang hiwalay na `EnrollmentStudentReviewTest` ay pumasa sa 11 tests / 75 assertions pagkatapos itama ang kulang na curriculum placements sa fixture. Sa diagnostic stop-on-first-failure run, 1,423 tests ang pumasa bago lumabas ang fixture issue na iyon; hindi iyon kumpletong passing full-suite run.

Pumasa ang 7-file focused frontend run sa 84 tests, kabilang ang dating timed-out credit-mapping test at ang na-update na Registrar AM/PM assertions. Pumasa ang final revision/service run sa 39 tests, kasama ang pending-submit regression at lahat ng review fixes. Pumasa ang final TypeScript at targeted ESLint. Pumasa rin ang Playwright recheck pagkatapos palawakin ang desktop dialog para magkasya ang weekly grid; nananatiling agenda/cards ang mobile layout.

Ang huling source-only global lint run ay **FAIL: 131 errors sa 50 files, 0 warnings** (`npx eslint . --format json --output-file ...`). Naayos ang direktang kaugnay na test diagnostics; ang iba ay naka-scope sa `docs/superpowers/plans/2026-10-28-quality-gates-follow-up.md`. Hindi tinatago ang mga ito sa blanket rule disable. Pumasa ang final targeted lint, PHPStan at TypeScript; hiwalay ang global lint gate. Ang buong JSON evidence ay nasa system Temp na `grc-qa-eslint-final.json`.

## Mga command para ulitin ang focused verification

Backend: gamitin muna ang nakahiwalay na MariaDB test database. Huwag gamitin ang presentation o production database dahil may database-resetting tests.

```powershell
php artisan test --filter=ReviseEnrollmentSubjectsEndpointTest
php artisan test --filter=EnrollmentsEndpointTest
php artisan test --filter=EligibleSubjectsEndpointTest
php artisan test --filter=RevisionSeatConcurrencyTest
php artisan test --filter=EnrollmentStudentReviewTest
php artisan test --filter=AuditEnrollmentRevisionsCommandTest
```

Frontend: nasa `components/portal/` ang tunay na test files; wala ang orihinal na ibinigay na `src/features/tests/` paths.

```powershell
npx vitest run src/features/components/portal/enrollment-revision-flow.test.tsx src/features/components/portal/enrollment-review-dialog.test.tsx
npx vitest run src/features/services/enrollment-service.test.ts
npx tsc --noEmit
node scripts/qa-revision-ui.mjs
```

## Rendered UI verification

Ang computer-use runtime ay hindi makapag-initialize dahil may nawawalang kernel assets. Hindi rin natapos sa oras ang full Next.js portal compilation. Gumamit ng hiwalay na Vite harness na nagre-render ng tunay na proposal component kasama ang project CSS at synthetic API fixtures; hindi ito pagbabago sa production architecture.

`node scripts/qa-revision-ui.mjs` passed sa desktop 1440×1000 at mobile 390×844: pagdagdag ng eligible section, total units, table/calendar switch, keyboard focus containment, walang page error at walang page-level horizontal overflow. Sinuri ang screenshots. Ang artifacts ay nasa system Temp directory na `grc-qa-revision-ui`. Hindi nito napapatunayan ang live browser → Laravel → database journey o kumpletong screen-reader accessibility.

## Matrix ng mas malawak na review

| Proseso | Mga test na batayan | Dapat patunayan |
| --- | --- | --- |
| Enrollment at eligibility | `EnrollmentsEndpointTest`, `EligibleSubjectsEndpointTest`, `ReviseEnrollmentSubjectsEndpointTest`, `RevisionSeatConcurrencyTest` | Owner/college access, requirements, state transitions, rollback, one last seat |
| Payment | `PaymentConfirmationEndpointTest` | Accounting authorization at repeated confirmation na walang duplicate |
| Withdrawal | `SubjectWithdrawalsEndpointTest` at withdrawal request tests | Ownership, approval, seat/grade/billing effects na hindi nadodoble |
| Queue | `QueueTicketsEndpointTest`, `FreshmanQueueTicketsEndpointTest` | Access at unique ticket/claim behavior |
| COM/COR | `EnrollmentDocumentsEndpointTest` | Sariling record access, authorized staff access, repeatable generation at download |
| Analytics at ibang roles | Existing analytics/policy/auth tests sa full backend suite | Least privilege; walang enrollment decision na awtomatikong nakabatay sa prediction |

Pumasa ang applicable backend matrix sa 232 tests / 885 assertions. Ang buong frontend run ay natapos sa 239 passing at 3 failing files: 2,000 passed at 4 failed tests (2,004 total). Na-load nito ang Chair dialog at Registrar assertions bago ang corrections; may isang credit-mapping timeout. Ang apat na failing cases ay pumasa sa mas bagong focused runs. Hindi ito itinatala bilang all-green full run.

**Buong backend run:** `php artisan test --compact` sa isolated MariaDB: **2,845 passed, 25 failed, 52,891 assertions**, 3,669.44 segundo. Ang ML service ay hindi binago sa slice na ito; hiwalay pa rin ang statistical/model evaluation.

## Natukoy sa unang buong backend run — bago ang kasunod na fixes

| Bilang | Test / sanhi | Resulta ng hiwalay na pagsusuri |
| --- | --- | --- |
| 9 failures | `EnrollmentStudentReviewTest`: walang curriculum placements ang naunang fixtures | Naayos ang helper; pumasa ang buong class sa 11 tests / 75 assertions. Na-load ng broad run ang lumang fixture bago ang correction. |
| 15 failures | `EvaluateCreditMappingStatusTest`: gumagamit noon ng `DatabaseTransactions` pero walang migration setup; walang `programs` table sa bagong schema | Naayos sa kasunod na slice gamit ang `RefreshDatabase`; 15 tests / 48 assertions passed sa dating empty schema. |
| 1 failure | `RestorePresentationTerm6ScriptTest::test_the_student_number_counter_is_left_alone_when_the_deleted_numbers_are_not_at_its_top` | Naulit ang 7 → 0 mismatch bago ayusin. Pumasa na ang counter regression at buong script suite sa kasunod na slice. |

Ang pinagsamang diagnostic run na `php artisan test --compact --filter='EvaluateCreditMappingStatusTest|RestorePresentationTerm6ScriptTest'` ay **33 passed, 1 failed, 131 assertions**. Pinatutunayan nitong nananatili ang restoration-script failure kahit may maayos na schema; hindi ito simpleng timeout. Hindi pa muling napatakbo nang all-green ang buong repository suite.

**Root cause na naayos:** Sa `backend/scripts/restore_presentation_term6.php`, ang dating `pr_giveBackStudentNumbers()` ay bumababa rin kapag walang matching student number, kahit hindi hawak ng mga tinanggal na test account ang kasalukuyang counter. Nilimitahan na ito sa documented top-number contract at dinagdagan ng surviving-suffix protection. May passing tests para sa unrelated higher counter, sunod-sunod na deleted top numbers, surviving student, gaps, ibang taon, dry run at paulit-ulit na execution. Walang restoration o data repair na ginawa sa aktuwal na database.

## Natitirang limitasyon

**Production build:** Passed ang isolated Next.js webpack production build, kabilang ang TypeScript at 14 generated pages. Walang deployment na ginawa; ibinalik ang generated-only tsconfig edits.

**Test cleanup:** Natapos ang dalawang backend runs bago isinara ang task-owned temporary MariaDB sa port 33317. Hindi isinara o nire-reset ang orihinal na database server.

- Hindi pa live end-to-end verification ang component harness.
- Ang lumang record na unknown ang author ay hindi hinuhulaan o awtomatikong nire-repair.
- Ang anumang issue sa ibang subsystem na lalabas sa full suites ay kailangang may sariling findings at scoped repair plan.
- Ang opisyal na grading, enrollment window at overload values ay dapat manggaling sa aprubadong configuration.
