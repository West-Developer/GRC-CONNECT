# ADR 0015 — Page Header Ownership and Portal Motion Policy

**Status:** Accepted
**Date:** 2026-07-31

## Context

A user-reported screenshot of the Eligible Subjects page showed the page
heading and description rendered twice ("Eligible Subjects" / "Review
curriculum..." immediately followed by "Eligible subjects" / "Review
which..."), a dashed placeholder border around fully-working content, the
page content centered like a splash screen, and a bare unstyled native
`<select>`. Investigating traced this to `PortalModulePage` wrapping every
connected workspace in a *second* header sourced from the module registry,
on top of the workspace's own `WorkspacePage` header — and reusing
`.portal-module-empty`, a CSS class built for *unbuilt* placeholder modules,
around real ones.

Two of the four root causes were misses from Phase 8a itself: that phase's
own audit (defect #9) named the duplicate-region problem and deferred it
with a comment calling it "Task 5 work" — Task 5 then never did it. Phase 8a
also told every workspace to "adopt the already-built-but-unused
`ui/select.tsx`" and left 10 workspaces still using raw `<select>`.

Separately, the user asked for motion, and asked for the fix to span all 19
workspaces, not just the reported page.

## Decisions

### 1. `WorkspacePage` is the page's sole header; the module registry is navigation-only

`PortalModulePage`'s connected branch no longer wraps `<ModuleComponent/>` in
its own `<section>`/`<h1>`/`<p>`/role badge. `WorkspacePage`'s internal
heading moved from `<h2>` to `<h1>`, since it is now the only heading on the
page. `CardTitle`'s default level moved from 3 to 2 to stay one level below
it — this required a mechanical shift of every explicit `level={3}`/`level={4}`
override across the codebase (each shifted down by one) to preserve correct
nesting everywhere a `DataTable`'s auto-carded mobile fallback sits inside an
already-carded workspace section.

Module registry `label`/`description` strings (`role-capabilities.ts`) remain
in use for the sidebar and breadcrumb — they were never the right place for
the page's *content* heading, since multiplexed workspaces
(`RegistrarRecordsWorkspace`, `AccountingPaymentWorkspace`) already derive a
more specific per-module heading of their own.

### 2. Reuse the existing design language; do not invent a new one

The portal borrowed the landing/login pages' *typography* but none of their
*material* qualities (a ruled-paper background, a signature gold hard-offset
shadow, red hairline rules, an `.eyebrow` micro-label). Every visual decision
in this pass ports an existing token or class rather than authoring a new
one: `WorkspacePage`'s header gained the existing `.eyebrow` class and a
serif display heading matching `.portal-overview-header`'s own pattern; a
new `.portal-workspace-highlight` utility adapts `.enrollment-folio`'s gold
offset-shadow idiom to a light background (the landing page's fully-inverted
ink-black treatment would look out of place surrounded by ordinary `Card`s);
new `--ease-house`/`--duration-*` tokens were seeded from values already in
use (the `ledger-enter` keyframe's curve, the `Sheet` component's duration),
not invented.

### 3. A motion library (`motion`) supplements, not replaces, the existing CSS vocabulary

Per explicit user decision. `motion` (the current framer-motion package) is
wrapped once in `src/features/components/portal/motion.tsx`
(`Reveal`, `StaggerList`/`StaggerItem`, `FadePresence`) so no workspace
imports the library directly, and reduced-motion handling is one decision
instead of nineteen — the existing global CSS
`prefers-reduced-motion` rule (`globals.css`) cannot reach JS-driven inline
transforms, so every export in `motion.tsx` checks `useReducedMotion()`
itself and renders a plain, motion-free wrapper when it's set.

Where plain CSS already does the job (the landing page's simple staggered
entrances), the existing `.reveal`/`.reveal--one..four` ladder stays as-is
and unextended. The library is reserved for what CSS keyframes handle
poorly: presence/exit animation and data-driven list staggering.

**`StaggerList`/`StaggerItem` are applied only to `<div>`-based card grids,
never to `<ul>`/`<li>` lists.** `StaggerItem` always renders a wrapping
`<div>`, and a `<div>` between `<ul>` and `<li>` is invalid nesting. Several
workspaces (schedule proposals' existing-proposals list, faculty input's
saved-availability list) keep their semantic list markup and simply don't
get the stagger treatment — a deliberate scope boundary, not an oversight.

### 4. `AsyncBoundary`'s state transitions are deliberately NOT wrapped in `AnimatePresence`

An early attempt crossfaded every `AsyncBoundary` state (loading → error →
empty → success) uniformly. This broke a real workspace test: on a refetch
(a filter change, pagination), the "loading" state's exit animation had to
complete before the new content mounted, and the test's synchronous
assertion — written correctly against the *previous*, non-animated behavior —
timed out waiting for content that was still mid-transition.
`AsyncBoundary` backs roughly 26 query sites across 19 workspaces; one
shared component's crossfade timing cannot be tuned per caller, and
guessing at a "safe enough" duration would trade a real defect for a
theoretical one. `FadePresence` stays available in `motion.tsx` for
narrower, single-workspace moments (a receipt banner, a dismissible notice)
where the blast radius of getting the timing wrong is one screen, not
twenty-six query sites.

### 5. Two different fixes for populating a `Select` from data that loads asynchronously

Several workspaces auto-select an "active" academic term into a
`react-hook-form`-controlled `Select` once reference data loads. Migrating
these off native `<select {...register(...)}>` onto `Controller`-wrapped
`Select` surfaced a genuine, previously-undetected bug: the auto-selection
silently stopped working. (The old tests never caught this — they asserted
only that a matching `<option>` existed in the DOM, which is trivially true
for a native select regardless of which option is actually selected; they
never asserted the *selected* value.)

Two entirely different-looking fixes turned out to be correct, depending on
one structural fact — **whether the field's `Controller` mounts for the
first time before or after the async data is known**:

**(a) The `Controller` is gated behind `AsyncBoundary`** (it only mounts once
its query has resolved, so the active term is already known at that point) —
e.g. `schedule-proposals-workspace.tsx`, `sections-workspace.tsx`. Fix:
leave that one field out of the form's top-level `defaultValues` entirely,
and give the `Controller` its own `defaultValue` prop plus a
`key={activeTerm?.id ?? "unselected"}`. A form-level default for a field —
even one seeded to a placeholder `0` — takes permanent precedence over a
later `Controller`'s own `defaultValue` for that same field, because
`react-hook-form` only honors a `Controller`'s `defaultValue` for a field
the form has never seen a default for. Once a field has *any* form-level
default, `setValue()`/`reset()` calls that arrive in the same render pass a
freshly-mounted `Controller` first registers in can be silently reverted by
that registration.

**(b) The `Controller` mounts unconditionally** (not gated behind
`AsyncBoundary` — the form renders immediately, before any query has
resolved) — e.g. `faculty-input-workspace.tsx`. Fix: the *opposite* —
keep the field in `defaultValues` as before, and restore the classic
`useEffect` + `form.setValue()` pattern once the active term loads. This
works here specifically *because* the `Controller` has already been stably
mounted for at least one full render by the time the effect fires — there
is no mount-vs-effect race, unlike case (a).

Applying fix (a)'s `key`/`defaultValue` trick to an unconditionally-mounted
field does not work: `shouldUnregister` defaults to `false` in
`react-hook-form` v7, so a field's first registration (seeded to `0` before
data loads) survives the `key`-triggered remount, and the second
`Controller` instance's `defaultValue` is ignored for the same reason as
above. Confirmed empirically — this was not a hypothetical, it was tried
and it silently failed.

**The distinguishing question for any future field like this:** does the
`Controller` for this field sit inside an `AsyncBoundary`'s `children`
render-prop (or similar conditional gate), or does it render immediately
alongside the rest of the form? Answer that first; it determines which of
the two fixes above applies.

### 6. Radix `Select`'s value must never toggle between `undefined` and a string

`<Select value={x ? String(x) : undefined}>` — toggling a controlled
component's value between `undefined` (an uncontrolled signal) and a defined
string trips React's controlled/uncontrolled detection, logging a console
warning and, observed directly in this codebase, silently failing to
re-render the placeholder text after the value is cleared. The fix is a
consistent `value={x ? String(x) : ""}` — always a string, `""` reserved
specifically for "no selection," matching Radix's own documented
convention. Two Selects that predate this pass
(`class-rosters-workspace.tsx`, `grade-submission-workspace.tsx`) carried
the same latent pattern without ever having exercised the bug; both were
corrected for consistency once the pattern was identified.

## Consequences

- Every future workspace gets the single-header, house-language-consistent
  chrome for free by construction — `PortalModulePage` no longer needs a
  per-workspace opt-out.
- A future field needing "populate a `Select` from data that loads after the
  form exists" must consult decision 5 above and pick the matching fix
  rather than reaching for whichever one was used most recently nearby.
- `AsyncBoundary` stays motion-free at the state-transition level by design;
  a future request to animate it needs a fresh design for per-query timing
  control, not just re-adding `AnimatePresence`.
- The `.portal-workspace-highlight` utility exists for panels that
  deliberately want extra visual weight (a review/confirmation panel, a
  receipt) — it is not the default `Card` treatment and should stay rare.

## Alternatives considered

**Keep the module-registry header and de-duplicate by removing the
workspace's own heading instead.** Rejected — the workspace's own title is
already more specific in several cases (multiplexed workspaces derive a
per-module heading), and the module registry's role there is navigation
(sidebar, breadcrumb), not page content.

**Animate `AsyncBoundary` with a very short, imperceptible duration to avoid
the test-timing issue.** Rejected — this trades a visible defect for an
invisible one; a future workspace with a slower refetch would hit the same
race, just less often and harder to reproduce.
