import { AuthError } from "@/features/auth/auth-error"
import type { AuthTokenStore } from "@/features/auth/auth-token"
import type {
  AuthGateway,
  AuthSession,
  Credentials,
  LoginOtpChallenge,
} from "@/features/auth/auth-types"
import { LoginOtpRequiredError } from "@/features/auth/login-otp-error"
import type { AuthenticatedUser } from "@/features/schemas/auth-schema"
import { isApiClientError } from "@/features/services/api-client"
import {
  fetchCurrentUser,
  login,
  logout,
  resendLoginOtp as resendLoginOtpRequest,
  verifyLoginOtp as verifyLoginOtpRequest,
  type AuthSessionPayload,
} from "@/features/services/auth-service"
import { loginWithGoogleCredential } from "@/features/services/google-login-service"

export function toSession(
  user: AuthenticatedUser,
  signedInAt: string = new Date().toISOString(),
): AuthSession {
  return {
    userId: String(user.id),
    displayName: user.name,
    role: user.role,
    college: user.college,
    signedInAt,
    superAdmin:
      user.role === "super_admin" || user.acting_context !== undefined
        ? {
            actingContext: user.acting_context ?? null,
          }
        : undefined,
    privacyNotice: {
      version: user.privacy_notice.version,
      acceptedVersion: user.privacy_notice.accepted_version,
      requiresAcceptance: user.privacy_notice.requires_acceptance,
    },
  }
}

/**
 * Authenticates against the real Laravel API with Sanctum bearer tokens.
 *
 * The token is owned exclusively by the injected `AuthTokenStore`
 * (`auth-token.ts`); this gateway never touches browser storage directly.
 */
export function createApiAuthGateway(tokenStore: AuthTokenStore): AuthGateway {
  let lastWriteSucceeded = true

  /** Shared by signIn/verifyLoginOtp: rejects the queue_kiosk role (it must
   * sign in through the Device Portal) and otherwise persists the token. */
  async function establishSession(
    payload: AuthSessionPayload,
  ): Promise<AuthSession> {
    if (payload.user.role === "queue_kiosk") {
      await logout(undefined, {
        token: payload.token,
        suppressUnauthorizedHandler: true,
      }).catch(() => undefined)

      throw new AuthError("QUEUE_KIOSK_REQUIRES_DEVICE_PORTAL")
    }

    lastWriteSucceeded = tokenStore.write(payload.token)

    return toSession(payload.user)
  }

  return {
    async signIn(credentials: Credentials): Promise<AuthSession> {
      let result: Awaited<ReturnType<typeof login>>

      try {
        result = await login({
          email: credentials.email.trim().toLowerCase(),
          password: credentials.password,
        })
      } catch (cause) {
        // A rejected credential must read the same to the user regardless of
        // whether the account was missing, wrong, or disabled — the API
        // already returns one generic 401 for all three.
        if (isApiClientError(cause) && cause.status === 401) {
          throw new AuthError("INVALID_CREDENTIALS")
        }

        throw cause
      }

      if (result.kind === "otp_required") {
        // No token was ever issued — nothing to persist or roll back.
        throw new LoginOtpRequiredError({
          challengeToken: result.challengeToken,
          email: result.email,
          expiresAt: result.expiresAt,
        })
      }

      return establishSession(result.session)
    },

    async verifyLoginOtp(
      challengeToken: string,
      code: string,
    ): Promise<AuthSession> {
      // A wrong/expired code surfaces as the API's own 422 field error
      // (`error.errors.code`) rather than an AuthError — the OTP form
      // applies it the same way reset-password-page applies its code error.
      return establishSession(await verifyLoginOtpRequest(challengeToken, code))
    },

    async resendLoginOtp(challengeToken: string): Promise<LoginOtpChallenge> {
      return resendLoginOtpRequest(challengeToken)
    },

    async signInWithGoogle(credential: string): Promise<AuthSession> {
      let session: AuthSessionPayload

      try {
        session = await loginWithGoogleCredential(credential)
      } catch (cause) {
        if (isApiClientError(cause) && cause.status === 404) {
          throw new AuthError("GOOGLE_ACCOUNT_NOT_FOUND")
        }

        if (isApiClientError(cause) && cause.status === 401) {
          throw new AuthError("INVALID_CREDENTIALS")
        }

        throw cause
      }

      return establishSession(session)
    },

    persistenceAvailable(): boolean {
      return lastWriteSucceeded
    },

    async restore(): Promise<AuthSession | null> {
      if (tokenStore.read() === null) {
        return null
      }

      try {
        return toSession(await fetchCurrentUser())
      } catch {
        // An expired, revoked, or unverifiable token yields no session. The
        // 401 handler in api-client has already cleared it.
        tokenStore.clear()

        return null
      }
    },

    async signOut(): Promise<void> {
      try {
        await logout()
      } catch {
        // The local session is cleared regardless; a failed revoke must not
        // strand the user in a signed-in UI.
      } finally {
        tokenStore.clear()
      }
    },

    clearSession(): void {
      tokenStore.clear()
    },
  }
}
