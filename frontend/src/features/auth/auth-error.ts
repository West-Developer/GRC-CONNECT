export type AuthErrorCode =
  | "INVALID_CREDENTIALS"
  | "QUEUE_KIOSK_REQUIRES_DEVICE_PORTAL"
  | "GOOGLE_ACCOUNT_NOT_FOUND"

export class AuthError extends Error {
  readonly code: AuthErrorCode

  constructor(code: AuthErrorCode) {
    super(code)
    this.code = code
    this.name = "AuthError"
  }
}

export function isAuthError(error: unknown): error is AuthError {
  return error instanceof AuthError
}
