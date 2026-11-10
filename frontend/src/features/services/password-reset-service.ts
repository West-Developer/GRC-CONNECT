import {
  forgotPasswordEnvelopeSchema,
  forgotPasswordSchema,
  type ForgotPasswordInput,
  type ForgotPasswordResponse,
} from "@/features/schemas/forgot-password-schema"
import {
  resetPasswordEnvelopeSchema,
  resetPasswordSchema,
  type ResetPasswordInput,
} from "@/features/schemas/reset-password-schema"
import { ApiClientError, postJson } from "@/features/services/api-client"

export const FORGOT_PASSWORD_PATH = "/api/v1/auth/forgot-password"
export const RESET_PASSWORD_PATH = "/api/v1/auth/reset-password"

function parse<T>(
  schema: {
    safeParse: (
      value: unknown,
    ) => { success: true; data: T } | { success: false; error: unknown }
  },
  value: unknown,
  label: string,
): T {
  const result = schema.safeParse(value)
  if (result.success) return result.data

  throw new ApiClientError({
    kind: "contract",
    message: `The ${label} did not match the published v1 contract.`,
    cause: result.error,
  })
}

export async function requestPasswordReset(
  input: ForgotPasswordInput,
): Promise<ForgotPasswordResponse> {
  const payload = parse(
    forgotPasswordSchema,
    input,
    "forgot-password request",
  )

  const json = await postJson(FORGOT_PASSWORD_PATH, payload)

  return parse(
    forgotPasswordEnvelopeSchema,
    json,
    "forgot-password response",
  ).data
}

export async function resetPassword(input: ResetPasswordInput): Promise<void> {
  const payload = parse(resetPasswordSchema, input, "reset-password request")

  const json = await postJson(RESET_PASSWORD_PATH, payload)

  parse(resetPasswordEnvelopeSchema, json, "reset-password response")
}
