# ADR 0055 — A Data Privacy Notice Every Person Reads and Agrees To, and a "Why We Ask" Notice at Every Form

**Status:** Accepted
**Date:** 2026-10-18
**Amends:** PRD §9 (security and privacy): adds the notice and the agreement.

## Context

The owner asked that the system show a **data privacy notice every time it takes details from a student, and say what the data is for**, applying the best practice for systems: **something to read first, and the student agrees**. They also asked that **everyone who is covered** is covered, not only students.

The law is the Data Privacy Act of 2012 (Republic Act No. 10173). Three things in it shape the design:

- A person must be **informed** before their data is collected: what, why, who sees it, how long, and their rights.
- Some of what the school keeps is **sensitive personal information**: age (the birth date) and education records (grades, credits, the Transcript of Records). Processing it generally needs the person's **consent** unless a law allows it, so an explicit agreement is the safe default.
- A person has rights over their data (to be informed, object, access, correct, remove or block, receive their data, complain to the National Privacy Commission, claim damages).

The school's own published privacy policy (grc.edu.ph/privacy-policy, 2023) covers website visitors only, names no Data Protection Officer, states no retention period, and gives `info@grc.edu.ph` as its contact. Policy values the school has not published must not be invented here.

## Decisions

1. **One notice, written once.** `frontend/src/features/lib/privacy/notice-content.ts` holds every word: who is covered, what is kept, why, who sees it, sharing, retention, security, what is asked of the person, staff duties, rights, and the short notice beside each form. The public page, the read-and-agree screen, Admission's notice and the form notices all read from it, so the purposes a person is told cannot differ from one screen to the next. A test pins that every data use says what, why and who, and that no retention period is stated.
2. **Who is covered** (on the page): applicants and incoming freshmen, current students of every type, former students, professors, staff who use the system, people who take a queue number, and parents, guardians or other people named (the system collects none today).
3. **Versioned, with the server as the authority.** `config/privacy.php` holds `notice_version` (`2026-10-18`). The frontend carries the version of its own wording (`PRIVACY_NOTICE_CONTENT_VERSION`). Every agreement names the version it is for, and the server refuses an agreement to any other version (422). If the two disagree (a deploy half done) the agree button is disabled with "reload to read the new version", so nobody is recorded as agreeing to wording they did not read. Changing the wording means changing both versions, and everyone is asked again.
4. **Read and agree at sign-in, for everyone who is a person.** `GET /auth/me` and every login payload carry `privacy_notice: { version, accepted_version, accepted_at, requires_acceptance }`. While `requires_acceptance` is true the portal shows the notice instead of the portal (`RequireSession`), with one checkbox ("I have read and understood…", different words for a student and for staff, the staff words add keeping the data confidential), "I agree and continue", and "I do not agree — sign out". It is asked on the first sign-in and again after the notice changes. The queue-kiosk device is a shared device, not a person, and is never asked.
5. **Agreeing is recorded.** Table `privacy_notice_acceptances` (user, version, method, recorded-by, time), unique per user, version and method, so a double click or a second tab is the same record and keeps its first time. `POST /auth/privacy-notice/accept` records `method = self`. Only a `self` record stops the screen from asking again. Each agreement is written to the audit log.
6. **Admission shows the notice when it takes the details.** Creating a student account now requires `privacy_notice_acknowledged` and `privacy_notice_version` (422 without). The form shows what the student is told and a link to the whole notice, and Admission ticks "I showed this Data Privacy Notice to the student…". The tick is **not** kept in the saved draft and is asked for each student. The server records it as `method = assisted`, with the staff member who confirmed it, in the same transaction as the account, so there is no account without it. It is evidence that the notice was shown; the student still agrees themself.
7. **Account setup asks first.** The three setup pages (student, faculty, staff) show the "why we ask" notice and the agreement checkbox before they take a password, a name, a college or a degree. They send the same two fields (optional on the API) and the server records a `self` agreement, so the first sign-in does not ask again. A request with the fields but a stale version is refused.
8. **A "why we ask" notice at every other form** that takes personal details: sign in, forgot password, a student's request to change their information, a Transferee or Returnee uploading a Transcript of Records, a withdrawal reason, inviting a professor, inviting staff, and a professor's availability. Each says what the form asks for, what it is used for, and who can see it, and links to the whole notice (new tab).
9. **Links.** The public page `/privacy` is linked from the landing footer, the sign-in page's notice, every form notice, and the portal's top bar and phone menu ("Data privacy").
10. **Contact without inventing an officer.** The notice shows the school's published email (`info@grc.edu.ph`) and names a Data Protection Officer, with phone, only when `PRIVACY_DPO_NAME`, `PRIVACY_DPO_EMAIL` and `PRIVACY_DPO_PHONE` are set on the server. **Retention** is stated only as "as long as needed for the purposes and as the law and CHED's rules require; the school's records-retention schedule decides the exact periods". No number is given.
11. **Not a security boundary.** The screen is where the person is informed and agrees; the API still answers every request. Blocking the API for a person who has not agreed would lock out staff mid-shift and the kiosk, and is a separate decision. Authorization is unchanged.
12. **Deleting an unused account** still works: `privacy_notice_acceptances` is on the IT Control delete allow-list (ADR 0054) as the account's own record.

## Consequences

- One migration (`2026_10_18_000001`), reversible; run it on every environment before the new screens work.
- **Everyone is asked once on their next sign-in** (about 6,400 student records and all staff on production), including students who already use the system. That is intended.
- The wording is a **draft for the school's Data Protection Officer or legal adviser to review**; it follows the Data Privacy Act and the system's real behaviour, but it is not legal advice. Once reviewed, any change to the words bumps `PRIVACY_NOTICE_VERSION` and `PRIVACY_NOTICE_CONTENT_VERSION` together.
- The school should name its Data Protection Officer and set `PRIVACY_DPO_*`; until then the page names nobody.
- A minor (under 18) is told to read the notice with a parent or guardian; the system does not collect a guardian's consent.
- The user guide does not mention the new first-sign-in screen yet (its pictures are of the screens before this change).
