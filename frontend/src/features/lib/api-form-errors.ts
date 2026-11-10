import type {
  FieldValues,
  Path,
  UseFormSetError,
  UseFormSetFocus,
} from "react-hook-form"

import { isApiClientError } from "@/features/services/api-client"

/**
 * Applies Laravel's named validation messages to matching React Hook Form
 * fields, then moves focus to the first one — otherwise a screen-reader or
 * keyboard user has no indication where the error text appeared. Other API
 * failures remain available to the caller for global UI.
 */
export function applyApiFieldErrors<T extends FieldValues>(
  error: unknown,
  setError: UseFormSetError<T>,
  setFocus?: UseFormSetFocus<T>,
): boolean {
  if (!isApiClientError(error) || error.status !== 422) {
    return false
  }

  let firstFieldName: Path<T> | undefined

  for (const [fieldName, messages] of Object.entries(error.fieldErrors ?? {})) {
    const message = messages[0]

    if (message) {
      setError(fieldName as Path<T>, { type: "server", message })
      firstFieldName ??= fieldName as Path<T>
    }
  }

  if (firstFieldName !== undefined) {
    setFocus?.(firstFieldName)
  }

  return true
}
