# ADR 0023 — Queue kiosk dual session and live Student queue view

**Status:** Accepted
**Date:** 2026-08-23

## Context

The payment queue already has an idempotent claim action at
`POST /api/v1/queue-tickets`: a Student claims their own pending-payment
ticket, while Accounting Staff can issue one on a Student's behalf. A Student
must now make their own claim only at a GRC-controlled Cashier kiosk, without
changing the Accounting workflow or turning a shared device identity into the
business actor.

The product also needs a privacy-preserving, near-live view of a Student's own
queue status in both the normal portal and the kiosk. The final implementation
uses `App\Http\Controllers\Api\V1\StudentQueueViewController` and
`App\Http\Resources\Api\V1\StudentQueueViewResource` for that view.

## Decision

### Device identity and surface

`queue_kiosk` is a non-human device identity, not a primary institutional
actor. `App\Domain\Identity\UserRole::QueueKiosk` owns no Student record and
is not the bearer that performs a Student claim. Its Sanctum token has only
the `queue-kiosk:claim` ability.

`App\Http\Middleware\EnsureQueueKioskUsesDeviceSurface` isolates a direct
kiosk token to `GET /api/v1/auth/me` and `POST /api/v1/auth/logout`. The
ordinary portal rejects a kiosk login and directs the operator to `/queue`.

### One conditional claim route

Keep the existing `POST /api/v1/queue-tickets` route and
`QueueTicketController::store`. `App\Http\Middleware\EnsureStudentQueueClaimUsesKiosk`
requires a Student request to carry a second active kiosk token in
`X-Queue-Kiosk-Token`; the token must belong to an active `queue_kiosk` user
and grant `queue-kiosk:claim`. Invalid device proof fails closed with the
normal authorization response.

Accounting Staff retains the existing on-behalf claim path, including its
optional-body `student_number` rule, and does not require or consume the kiosk
header. `EnrollmentPolicy::claimQueueTicket` remains the authorization source
for both actors.

Duplicate same-method/path routes were rejected because Laravel does not fall
through to a second route after middleware rejects the first. A composite
server session or token-exchange endpoint was also rejected: the Student and
device tokens have independently useful, narrow lifetimes, and only the claim
requires device proof.

### Reversible device credential

The shared kiosk password is intentionally recoverable for the
Accounting-authorized credential workspace. `users.password` remains a
one-way hash; `queue_kiosk_credentials.secret_ciphertext` is encrypted and is
decrypted only by `ViewQueueKioskCredential`. This is an explicit exception
for a shared device secret, not a pattern for personal-user passwords: a
compromise of both the database and `APP_KEY` can reveal it.

`QueueKioskCredentialController` exposes the private, no-store
`GET|PUT /api/v1/queue-kiosk-credential` resource to Accounting Staff only.
`ChangeQueueKioskPassword` locks the device rows, updates the hash and
ciphertext in one transaction, revokes all outstanding kiosk tokens, and
records safe audit metadata. Rotation therefore invalidates an already-running
kiosk session on its next validation or claim; audit data and logs never hold
plaintext passwords, hashes, ciphertext, bearer tokens, or request headers.

### Dual browser sessions and independent failures

`frontend/src/features/kiosk/kiosk-token.ts` is the sole owner of the
persistent device token (`grc.kiosk-token.v1`). The normal portal token remains
solely owned by `auth-token.ts` (`grc.auth-token.v1`). On `/queue`, the
Student bearer and minimal identity exist only in mounted client state; they
are cleared on Done, device sign-out, or page refresh and never enter browser
storage or query keys.

Kiosk and in-memory Student requests provide explicit bearer overrides and
suppress the portal-wide unauthorized handler. A kiosk or Student `401` is
handled within its own session and cannot clear a concurrently stored portal
session. The route is outside `/portal` and does not mount the portal auth
provider.

### Live Student view

`GET /api/v1/queue-status` returns only the authenticated Student's stage,
own ticket, current serving ticket, upcoming ticket numbers, and cut-off
state. `StudentQueueLivePanel` is reused in kiosk, default portal, and compact
portal modes. The normal portal only shows the live view; it never offers a
claim action.

`useStudentQueueQuery` polls every three seconds, continues background polling,
and refetches on every window focus. This is deliberately scoped to the live
Student queue. Browsers and operating systems may still throttle or suspend a
background tab, so the interface tells the Student to keep the page open and
visible near service time.

## Consequences

Student self-claims now require both the Student bearer and kiosk proof, while
Accounting can continue serving a Student when the kiosk flow is unavailable.
The additional token and reversible shared secret increase the security
surface, mitigated by device-only API isolation, least-privilege ability,
no-store credential responses, audited credential access, rotation revocation,
and independent `401` handling.

## Non-goals

- Public queue boards or disclosure of other Students' identities.
- Push notifications, service workers, SMS, or email queue-call alerts.
- Kiosk hardware integrations such as camera, QR/barcode reader, printer, or
  scanner.
- New queue priority, skip, no-show, or inactivity-timeout policy.
- Changes to payment confirmation, ticket completion, queue-cycle ordering,
  or cookie/CSRF authentication.
