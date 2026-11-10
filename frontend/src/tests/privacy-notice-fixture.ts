/**
 * The `privacy_notice` part of a `GET /auth/me` payload for a person who has already agreed to the current Data
 * Privacy Notice (ADR 0055), so a test of anything else does not meet the read-and-agree screen. Spread it into a
 * user fixture: `{ ...user, privacy_notice: agreedPrivacyNotice }`.
 */
export const agreedPrivacyNotice = {
  version: "2026-10-18",
  accepted_version: "2026-10-18",
  accepted_at: "2026-10-18T00:00:00Z",
  requires_acceptance: false,
} as const
