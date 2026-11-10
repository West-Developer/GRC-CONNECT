# ADR 0027 — Queue Announce Signal and Student-Side Call Sound

**Status:** Accepted
**Date:** 2026-09-24
**Amends:** ADR 0023 (dual-session kiosk and live student view). Its non-goals (push notifications, service workers, SMS, email alerts, public boards) are unchanged.

## Context

Stakeholder feedback (Google Doc "WESTLIE", 2026-09-24) asked that the sound played
when Accounting Staff call a queue number also play on the **student's** device, not
only on the Cashier's.

Two things were found:

1. The student's device already learns of a call. `GET /api/v1/queue-status` is polled
   every three seconds and `useQueueCallAlert` reacts to the student's own ticket going
   `waiting` → `serving` (toast, page title, vibration, and, when enabled, a tone and the
   spoken ticket number). But audio could never be enabled: browsers refuse to play audio
   until the page has had a user gesture, the only control that created the audio context
   (a "Turn on sound" button) had been removed from the student panel, and the panel's
   copy said sound played automatically. Nothing else ever called `enableSound`.
2. The Cashier's **Announce ticket** button was purely client-side (a local chime and
   speech). Pressing it again to re-call a student who had not come forward could not
   reach that student's device at all.

## Decisions

**1. The student's device rings for two events.** The existing edge (own ticket
`waiting` → `serving`) and a new one: the Cashier announcing the ticket again while it
is `serving`.

**2. The announce is a counter, not an event stream.** `queue_tickets.announce_count`
(`unsignedSmallInteger`, default 0). `PATCH /api/v1/queue-tickets/{id}` accepts
`action: announce`: Accounting Staff only (`QueueTicketPolicy::update`), only while the
ticket is `serving` (422 otherwise), and it adds one. The student's polled view compares
the number between polls, so no websocket, push or new route is needed and ADR 0023's
three-second polling stays the delivery mechanism.

**3. Repeatable and not audited.** Every press is meant to ring again, so there is
nothing to make idempotent, and a retried request at worst rings once more. It is not a
change to the ticket, so no audit row is written (the same reasoning that keeps the bulk
"complete the previous serving ticket" update out of the audit log), and no notification
row is created.

**3a. Exposure.** `announce_count` is returned only on the student's own ticket in
`/queue-status` (`StudentQueueViewResource`), never on the staff `QueueTicketResource`
and never for another student's ticket. The frontend schema defaults it to `0`, so a
response from a server that predates the column still parses.

**4. Sound defaults on for a student's own device, off for the kiosk.** A saved choice
always wins (an explicit "off" is never overridden). When sound is wanted but the browser
has not allowed audio, the student's **first tap or key press** anywhere on the page is
the gesture that creates and resumes the audio context (and primes speech synthesis); that
automatic unlock does **not** save a preference the student did not make. The
"Turn on sound / Turn off sound" control is back on the panel for muting or enabling
explicitly. The shared kiosk keeps sound opt-in, since it is a shared device.

**5. The alert lives above the collapsible card.** `useQueueCallAlert` is now called in
`EnrollmentQueuePaymentPanel`, outside the accordion content that unmounts when the card
is collapsed, and handed to `StudentQueueLivePanel` (which still runs its own alert when
used without a parent, e.g. on the kiosk and the overview page). Collapsing the card no
longer closes the audio context and silences the call.

## Alternatives considered

- **Websockets, SSE or push.** Rejected by ADR 0023; the polled view is enough at
  three-second granularity.
- **A notification row per announce.** Would flood the student's notification list with
  identical entries for a transient, live signal.
- **An audit row per announce.** Buries the real ticket transitions in the log.
- **Keep the announce local-only.** Does not meet the request.
- **A second, dedicated announce endpoint.** More surface for the same authorization and
  state check that `PATCH /queue-tickets/{id}` already performs.

## Consequences

- A phone with a locked screen, a silent switch (iOS ignores Web Audio while it is on) or a
  heavily throttled hidden tab still cannot ring without push or a service worker, which
  remain non-goals. The panel keeps the "keep this page open" guidance and the visual alert
  (toast, title, alert region, vibration where supported).
- A ring can lag a call by up to one poll interval (three seconds).
- One column migration (`2026_09_24_000002_add_announce_count_to_queue_tickets_table`).
  It rolls back with a plain `dropColumn`. Note the repository's separate, pre-existing
  full-rollback failure in a `queue_cycles` migration's `down()` (see PROGRESS.md) is
  unrelated to it.
- The chime a student hears is still the single 880 Hz tone plus the spoken ticket number;
  the Cashier's two-note chime is not replicated on student devices (the existing hook
  tests pin the single-oscillator behaviour).
- Tests: `QueueTicketsEndpointTest` (announce counts, serving-only, Accounting Staff-only,
  no audit), `StudentQueueViewEndpointTest` (counter visible to the student),
  `use-queue-call-alert.test.tsx` (re-ring on counter increase, default-on, first-tap
  unlock, explicit off respected), `student-queue-live-panel.test.tsx` (toggle and honest
  copy), `accounting-payment-workspace.test.tsx` (Announce sends the action).
