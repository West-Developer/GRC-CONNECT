import type { UserRole } from "@/features/auth/roles"

export interface SuperAdminSessionState {
  actingContext: {
    role: UserRole
    college?: "ccs" | "coe" | "coa" | "cbae" | null
  } | null
}

/**
 * Where the signed-in person stands on the Data Privacy Notice (ADR 0055). `requiresAcceptance` makes the
 * portal show the notice before anything else. Absent only in hand-built test sessions: a real session always
 * carries it, because the API sends it with every user.
 */
export interface PrivacyNoticeState {
  version: string
  acceptedVersion: string | null
  requiresAcceptance: boolean
}

export interface AuthSession {
  userId: string
  displayName: string
  role: UserRole
  college?: "ccs" | "coe" | "coa" | "cbae" | null
  signedInAt: string
  superAdmin?: SuperAdminSessionState
  privacyNotice?: PrivacyNoticeState
}

export interface Credentials {
  email: string
  password: string
}

/**
 * The authentication gateway. One implementation exists — `api-auth-gateway`,
 * which talks to Laravel with Sanctum bearer tokens.
 *
 * The gateway owns persistence entirely: it writes the token through an
 * injected `AuthTokenStore` and rebuilds the session from the server on reload.
 * `AuthProvider` never touches storage itself.
 */
export interface LoginOtpChallenge {
  challengeToken: string
  email: string
  expiresAt: string
}

export interface AuthGateway {
  /**
   * Throws `LoginOtpRequiredError` (never resolves with a partial session)
   * when the account requires a fresh email OTP — no token exists yet, so
   * the caller must complete the challenge via `verifyLoginOtp`.
   */
  signIn(credentials: Credentials): Promise<AuthSession>

  /** Completes a login-OTP challenge and establishes the session. */
  verifyLoginOtp(challengeToken: string, code: string): Promise<AuthSession>

  /** Rotates a still-live login-OTP challenge and re-sends the code. */
  resendLoginOtp(challengeToken: string): Promise<LoginOtpChallenge>

  /**
   * Google Sign-In: exchanges a Google Identity Services ID token for a
   * session. Throws `AuthError("GOOGLE_ACCOUNT_NOT_FOUND")` when the
   * verified Google email matches no active GRC account — this never
   * creates one.
   */
  signInWithGoogle(credential: string): Promise<AuthSession>

  /** Rebuilds the session from the stored token on page load. */
  restore(): Promise<AuthSession | null>

  /** Revokes the session server-side. Failures must not block local sign-out. */
  signOut(): Promise<void>

  /**
   * Clears the stored token without a server round-trip. For when the token
   * is already known-invalid (the API rejected it with 401) — a revoke call
   * would just 401 again.
   */
  clearSession(): void

  /**
   * Whether the last session actually persisted (browser storage can be
   * disabled or full). Drives the "cannot be restored after refresh" warning.
   */
  persistenceAvailable(): boolean
}
