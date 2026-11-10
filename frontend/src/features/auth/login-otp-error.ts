/**
 * Thrown by `AuthGateway.signIn()` in place of resolving whenever the API
 * reports `otp_required` for a login attempt — credentials were correct, but
 * no session exists yet, so the caller must present the code-entry step and
 * then call `verifyLoginOtp`/`resendLoginOtp` rather than retry `signIn`.
 * Sibling to `auth-error.ts`'s `AuthError`/`isAuthError()`.
 */
export class LoginOtpRequiredError extends Error {
  readonly challengeToken: string

  readonly email: string

  readonly expiresAt: string

  constructor(challenge: {
    challengeToken: string
    email: string
    expiresAt: string
  }) {
    super("LOGIN_OTP_REQUIRED")
    this.name = "LoginOtpRequiredError"
    this.challengeToken = challenge.challengeToken
    this.email = challenge.email
    this.expiresAt = challenge.expiresAt
  }
}

export function isLoginOtpRequiredError(
  error: unknown,
): error is LoginOtpRequiredError {
  return error instanceof LoginOtpRequiredError
}
