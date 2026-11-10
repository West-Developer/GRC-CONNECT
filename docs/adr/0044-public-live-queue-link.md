# ADR 0044 — A Public, Token-Scoped Live Queue Link for Freshmen

**Status:** Accepted
**Date:** 2026-10-04
**Amends:** ADR 0023 and the 2026-08-23 live-view spec (their non-goal "no public queue display"), and ADR 0027 (which kept that non-goal)
**Related:** ADR 0043 (freshmen pay first), ADR 0029 (polling cadence)

## Context

ADR 0023 deliberately left out a public queue board: nothing about the queue was readable without a signed-in student, and no other student's identity was ever shown. A freshman now takes a queue number at Admission **before they have an account** (ADR 0043), so they cannot sign in to see where they are. The stakeholder asked that the emailed queue number come with a link to a live view that shows only their own number and which numbers the Cashier is calling.

## Decisions

1. **One unauthenticated endpoint, `GET /api/v1/public/queue-view/{token}`**, and one client page, `/queue/live?t=<token>`. The page is outside the portal's auth provider (like the kiosk), so it never reads or sends a portal token.
2. **The token is the credential.** It is 48 random alphanumeric characters, unique per ticket, and only its SHA-256 is stored (`queue_tickets.public_token_hash`); the plain token exists only in the email. The route accepts only a 48-character alphanumeric value; anything else, and any unknown token, is an ordinary 404.
3. **Same shape as the signed-in student's own view** (`student_queue_view`), so the live panel is reused: the freshman's own ticket (number, status, position, how many times it was called), the number being served, and the next waiting numbers. The stage is `freshman_awaiting_payment` or, once the Cashier has confirmed the first payment, `freshman_paid`. **Ticket numbers only** — never a name, e-mail or student number, and nothing about any other person.
4. **The link is sent again by rotating the token** (`POST /student-profiles/{id}/queue-link-resends`, Admission only): the new email carries a new token and the old link stops working at once. Rotation is refused once the first payment is confirmed.
5. **Rate limiting is per link, not per address** (`public-queue-view`: 40 requests a minute per token plus a generous 3,000 a minute per IP). The page polls every 3 seconds (20 a minute), and a campus full of freshmen behind one network address must not throttle each other. The page stops polling once the server says it does not know the link; any other failure keeps retrying quietly.
6. **No caching**: `Cache-Control: no-store, private`.
7. **The link base is the configured `app.frontend_url`**, never an `Origin` or `Referer` header, because a link that carries a secret must not be steerable to another host.

## Consequences

- This is the first unauthenticated read of queue data. The exposure is limited to ticket numbers for whoever holds a valid token, and a token cannot be guessed (62^48 values) or enumerated (the response to a wrong token is a plain 404).
- A leaked link shows the holder the freshman's own number and the board, nothing more; sending the link again (rotation) revokes it.
- The link works while the ticket is waiting or serving and for as long as the token is unchanged; it keeps working after payment (showing `freshman_paid`) until the cycle that held the ticket is gone. There is no separate expiry.
- ADR 0023's other non-goals still stand: no public board of names, no push, no SMS.
