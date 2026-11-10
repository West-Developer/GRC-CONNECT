import { isApiClientError } from "@/features/services/api-client"

/**
 * Every message worth showing for a failed request: the server's per-field
 * validation messages when it sent some, otherwise the error's own message.
 */
export function apiErrorMessages(
  error: unknown,
  fallback: string,
): readonly string[] {
  if (isApiClientError(error)) {
    const fieldErrors = Object.values(error.fieldErrors ?? {}).flat()
    if (fieldErrors.length > 0) return fieldErrors
    return [error.message]
  }

  if (error instanceof Error) return [error.message]

  return [fallback]
}
