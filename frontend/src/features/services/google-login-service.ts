import { authEnvelopeSchema } from "@/features/schemas/auth-schema"
import { ApiClientError, postJson } from "@/features/services/api-client"
import type { AuthSessionPayload } from "@/features/services/auth-service"

export const GOOGLE_LOGIN_PATH = "/api/v1/auth/google"

function contractError(cause: unknown): ApiClientError {
  return new ApiClientError({
    kind: "contract",
    message:
      "The API responded, but its authentication payload did not match the published v1 contract.",
    cause,
  })
}

/**
 * Posts the signed ID token Google Identity Services handed the browser.
 * Reuses `authEnvelopeSchema` — a successful Google sign-in returns the
 * identical `auth-session` envelope a password login does.
 */
export async function loginWithGoogleCredential(
  credential: string,
  signal?: AbortSignal,
): Promise<AuthSessionPayload> {
  const payload = await postJson(GOOGLE_LOGIN_PATH, { credential }, signal)
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
