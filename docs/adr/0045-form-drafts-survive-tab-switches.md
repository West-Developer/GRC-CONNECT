# ADR 0045 — Half-typed forms survive tab switches, page changes and reloads

**Status:** Accepted and implemented for the Admission Student Records page (Create Account form and the Student Directory search box)
**Date:** 2026-10-04
**Related:** `enrollment-draft-store.ts` (the earlier draft store for a student's section picks), ADR 0013 (client-rendered presentation layer), ADR 0029 (React Compiler)

## Context

Admission staff type a long form (name, email, address, program, category, student type, the ticked requirements). The page keeps it in a local `react-hook-form` instance, and Radix tabs unmount an inactive tab, so switching to "Student Directory" and back threw the whole form away; leaving for another portal page did the same, and so did an accidental reload. The owner reported it directly ("kapag nakapag input na ng details sa box and nag palit ng tab or nav dapat hindi mawawala").

## Decision

1. **`form-draft-store.ts`** keeps drafts client-side, per user and per form key, following `enrollment-draft-store.ts` (injectable storage, never throws when storage is missing or refused). It differs because these forms hold personal data about people who are not users yet:
   - it lives in **`sessionStorage`**, so a draft dies with the browser tab;
   - a draft older than **12 hours** is dropped when it is read;
   - **`clearAll()` runs on an explicit sign-out**, so the next person at the machine starts clean;
   - a session that merely **expires does not wipe** drafts: that is exactly when a half-typed form matters most, drafts are per user, and signing in again brings back that person's own.
2. **`useDraftForm`** wraps `useForm`: it starts from the saved draft on the first render, saves a moment after typing pauses, writes once more as the form unmounts (so the last keystrokes survive a tab switch), and saves nothing while the form still equals how it started or when nobody is signed in. After a successful save the page calls `clear()` and `rebase()`, so a value deliberately carried over to the next student (the program and year) is not mistaken for something typed.
3. **`usePersistedState`** does the same for a single plain value (the directory's search box and the search that was run).
4. The Create Account form shows a **"Clear form"** button and a one-line notice ("kept in this browser until you create the account or sign out") whenever a draft exists, so the person can see it and discard it.
5. The draft is **client-side only, by design**: it is UX convenience, not business state. Nothing is stored server-side and nothing reaches Registrar-facing views.

## The React Compiler trap

The compiler recognises `useForm` by name and skips components that call `watch()` or spread `register()` props, because they cannot be memoized safely (the "Compilation Skipped: Use of incompatible library" lint warning). Behind `useDraftForm` it cannot see that any more, so it compiles the component; the memoized `register()` props then keep stale ref callbacks, and a `reset()` (discarding a draft) empties the dropdowns but leaves the old text in the boxes. The jsdom tests do not run the compiler, so only a real browser shows it. **A component that uses `watch`/`register` from `useDraftForm` must opt out with a `"use no memo"` directive**, as `CreateAccountPanel` does.

## Consequences

- Staff no longer lose a half-typed form to a tab switch, a page change or a reload. Verified in a real browser against the dev server with the API mocked: text boxes, the three dropdowns, tab switch, route change and back, full reload, "Clear form" (typed and restored), the directory search, and the sign-out wipe.
- Other long forms can adopt `useDraftForm` with a version-suffixed key (bump it when the form's fields change shape). Only the Admission page does so today.
- A draft holds a prospective student's name, email and address in the staff member's own browser for up to 12 hours or until the tab closes, whichever is first, unless cleared; this is the same exposure class as the bearer token the app already keeps in `localStorage`.
