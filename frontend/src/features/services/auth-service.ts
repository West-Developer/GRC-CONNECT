import {
  authEnvelopeSchema,
  loginOtpChallengeEnvelopeSchema,
  loginResponseEnvelopeSchema,
  privacyNoticeInfoEnvelopeSchema,
  userEnvelopeSchema,
  type AuthenticatedUser,
  type PrivacyNoticeInfo,
} from "@/features/schemas/auth-schema"
import {
  ApiClientError,
  type AuthenticatedRequestOptions,
  getAuthenticatedJson,
  getJson,
  postAuthenticatedJson,
  postJson,
} from "@/features/services/api-client"

export const AUTH_LOGIN_PATH = "/api/v1/auth/login"
export const PRIVACY_NOTICE_PATH = "/api/v1/privacy-notice"
export const AUTH_PRIVACY_NOTICE_ACCEPT_PATH = "/api/v1/auth/privacy-notice/accept"
export const AUTH_LOGOUT_PATH = "/api/v1/auth/logout"
export const AUTH_ME_PATH = "/api/v1/auth/me"
export const AUTH_VERIFY_LOGIN_OTP_PATH = "/api/v1/auth/login/verify-otp"
export const AUTH_RESEND_LOGIN_OTP_PATH = "/api/v1/auth/login/resend-otp"

export interface LoginCredentials {
  email: string
  password: string
}

export interface AuthSessionPayload {
  token: string
  expiresAt: string | null
  user: AuthenticatedUser
}

export interface LoginOtpChallengePayload {
  challengeToken: string
  email: string
  expiresAt: string
}

export type LoginResult =
  | { kind: "authenticated"; session: AuthSessionPayload }
  | ({ kind: "otp_required" } & LoginOtpChallengePayload)

function contractError(cause: unknown): ApiClientError {
  return new ApiClientError({
    kind: "contract",
    message:
      "The API responded, but its authentication payload did not match the published v1 contract.",
    cause,
  })
}

function toLoginResult(payload: unknown): LoginResult {
  const parsed = loginResponseEnvelopeSchema.safeParse(payload)

  if (!parsed.success) {
    throw contractError(parsed.error)
  }

  const { data } = parsed.data

  if (data.type === "login-otp-challenge") {
    return {
      kind: "otp_required",
      challengeToken: data.challenge_token,
      email: data.email,
      expiresAt: data.expires_at,
    }
  }

  return {
    kind: "authenticated",
    session: {
      token: data.token,
      expiresAt: data.expires_at,
      user: data.user,
    },
  }
}

export async function login(
  credentials: LoginCredentials,
  signal?: AbortSignal,
): Promise<LoginResult> {
  return toLoginResult(await postJson(AUTH_LOGIN_PATH, credentials, signal))
}

/**
 * The Queue Kiosk's own claim-ticket flow: a Student types their own
 * credentials directly into an already-authenticated physical kiosk device
 * to identify themselves. Carries the kiosk's own live token as proof of
 * physical possession (mirrors `claimQueueTicket`'s `X-Queue-Kiosk-Token`
 * convention) so the backend can skip the email-OTP step a walk-up kiosk has
 * no realistic way to complete — see `LoginController::isVerifiedByKioskDevice()`.
 */
export async function loginBehindQueueKiosk(
  credentials: LoginCredentials,
  kioskToken: string,
  signal?: AbortSignal,
): Promise<LoginResult> {
  return toLoginResult(
    await postAuthenticatedJson(AUTH_LOGIN_PATH, credentials, signal, {
      headers: { "X-Queue-Kiosk-Token": kioskToken },
      suppressUnauthorizedHandler: true,
    }),
  )
}

export async function verifyLoginOtp(
  challengeToken: string,
  code: string,
  signal?: AbortSignal,
): Promise<AuthSessionPayload> {
  const payload = await postJson(
    AUTH_VERIFY_LOGIN_OTP_PATH,
    { challenge_token: challengeToken, code },
    signal,
  )
  const parsed = authEnvelopeSchema.safeParse(payload)

  if (!parsed.success) {
    throw contractError(parsed.error)
  }

  return {
    token: parsed.data.data.token,
    expiresAt: parsed.data.data.expires_at,
    user: parsed.data.data.user,
  }
}

export async function resendLoginOtp(
  challengeToken: string,
  signal?: AbortSignal,
): Promise<LoginOtpChallengePayload> {
  const payload = await postJson(
    AUTH_RESEND_LOGIN_OTP_PATH,
    { challenge_token: challengeToken },
    signal,
  )
  const parsed = loginOtpChallengeEnvelopeSchema.safeParse(payload)

  if (!parsed.success) {
    throw contractError(parsed.error)
  }

  return {
    challengeToken: parsed.data.data.challenge_token,
    email: parsed.data.data.email,
    expiresAt: parsed.data.data.expires_at,
  }
}

export async function fetchCurrentUser(
  signal?: AbortSignal,
  options?: AuthenticatedRequestOptions,
): Promise<AuthenticatedUser> {
  const payload = await getAuthenticatedJson(AUTH_ME_PATH, signal, options)
  const parsed = userEnvelopeSchema.safeParse(payload)

  if (!parsed.success) {
    throw contractError(parsed.error)
  }

  return parsed.data.data
}

/** The Data Privacy Notice's current version and contact. Public: it is read before anyone signs in. */
export async function fetchPrivacyNoticeInfo(
  signal?: AbortSignal,
): Promise<PrivacyNoticeInfo> {
  const parsed = privacyNoticeInfoEnvelopeSchema.safeParse(
    await getJson(PRIVACY_NOTICE_PATH, signal),
  )

  if (!parsed.success) {
    throw contractError(parsed.error)
  }

  return parsed.data.data
}

/**
 * The signed-in person agrees to the version they were shown. The answer is the same user payload as
 * `GET /auth/me`, so the session is updated from it.
 */
export async function acceptPrivacyNotice(
  noticeVersion: string,
  signal?: AbortSignal,
): Promise<AuthenticatedUser> {
  const parsed = userEnvelopeSchema.safeParse(
    await postAuthenticatedJson(
      AUTH_PRIVACY_NOTICE_ACCEPT_PATH,
      { notice_version: noticeVersion },
      signal,
    ),
  )

  if (!parsed.success) {
    throw contractError(parsed.error)
  }

  return parsed.data.data
}

export async function logout(
  signal?: AbortSignal,
  options?: AuthenticatedRequestOptions,
): Promise<void> {
  await postAuthenticatedJson(AUTH_LOGOUT_PATH, undefined, signal, options)
}
