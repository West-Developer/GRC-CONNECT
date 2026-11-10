# ADR 0053 — Admission Types the Full Middle Name; It Is Shown as an Initial Everywhere Except to Admission and the Student

**Status:** Accepted
**Date:** 2026-10-16
**Amends:** the structured name fields (2026-08-26: `first_name`, `middle_initial`, `last_name`, `suffix`, with `name` composed by `PersonName::compose()`).

## Context

Admission's Create Account form asked for a "Middle initial". Staff typed the whole middle name in it (the field took up to 10 characters), so a student named "West Ramga Ragma" was stored and displayed as "West Ramga. Ragma". The owner wants Admission to enter the **middle name**, but names are still to appear with the **initial only** in every list, header and document; the full middle name is for Admission and for the student's own profile page.

## Decisions

1. **Store the full name, derive the initial.** `users.middle_name` (`VARCHAR(100)`, nullable) and `student_profile_change_requests.requested_middle_name` are new. `middle_initial` stays and is **always derived** from the middle name (`PersonName::initial()`: its first letter, upper-case), so the two cannot disagree and `PersonName::compose()` and the composed `name` ("West R. Ragma") are unchanged. Nothing is backfilled: an older account has only an initial and the full name is not known. Reversible migration `2026_10_16_000001`.
2. **One place decides what to store.** `PersonName::middle($submitted, $currentName, $currentInitial)` returns the pair to store. A value that is the same as what is stored changes nothing, however it is typed. In particular an older account's edit form shows its **initial** in the Middle name box, and sending it back unchanged must not turn that letter into a "full middle name", nor wipe it. A bare initial that matches the initial of the stored name is also "no change". An empty value clears both.
3. **Who receives it.** `StudentProfileResource` sends `middle_name` only to Admission staff and to the student whose profile it is; every other role (the Registrar Head's student profile included) gets `null`. The change-request resource, which only the student and Admission read, carries it in `official` and `requested`. Everything else shows `name` / `middle_initial`.
4. **Inputs.** `middle_name` (max 100) replaces `middle_initial` on the provision, update and change-request requests. A student's change request proposes a middle name; approving it applies the name and its initial together. A request is a no-op (refused, as before) when nothing differs from the official record, the middle name included.
5. **UI.** Admission's Create Account and the Student Directory record dialog say **Middle name**; the student's own Student Information page shows it under their official identity (the initial, with a dot, for an older account) and asks for a **Proposed middle name** in a change request. The create-form draft key moved to `.v3`, so a draft saved with the old field name is not restored half-filled.

## Consequences

- No screen, list, report header or document changes: they already printed the composed `name`.
- The CHED reports still print the initial in their middle-name column. They can use the full middle name where it is known once the owner confirms that is wanted; it is not done here.
- Pending change requests created before this change carry only `requested_middle_initial`; approving one applies that initial and leaves the full middle name empty.
- Needs the migration run on every environment before the new screens work (a missing column is a 500 on provisioning and on the profile).
