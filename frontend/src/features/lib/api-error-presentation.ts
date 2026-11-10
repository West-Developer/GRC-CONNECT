import { ZodError } from "zod"

import { isApiClientError } from "@/features/services/api-client"

export interface ErrorPresentation {
  title: string
  message: string
  action?: { label: string; onClick: () => void }
}

const CONNECTION_MESSAGE =
  "The public enrollment API is not available right now. Confirm that the Laravel service is running, then try again."

function buildAction(
  onRetry: (() => void) | undefined,
): ErrorPresentation["action"] {
  return onRetry ? { label: "Try again", onClick: onRetry } : undefined
}

/**
 * Maps an error thrown by the API client to PRD §12.4's named states
 * (authorization, not found, conflict, throttled, dependency failure) so
 * every workspace renders the same copy for the same failure instead of one
 * generic "could not be loaded" message regardless of cause.
 */
export function getStatePresentation(
  error: unknown,
  options: { onRetry?: () => void } = {},
): ErrorPresentation {
  const action = buildAction(options.onRetry)

  // A payload that fails a service's schema is a contract problem, not a lost connection.
  if (error instanceof ZodError) {
    return {
      title: "Unexpected API response",
      message:
        "The API responded, but the data did not match the published v1 contract.",
      action,
    }
  }

  if (!isApiClientError(error)) {
    return {
      title: "Connection interrupted",
      message: CONNECTION_MESSAGE,
      action,
    }
  }

  switch (error.kind) {
    case "configuration":
      return {
        title: "API address needs attention",
        message: error.message,
        action,
      }
    case "contract":
      return {
        title: "Unexpected API response",
        message: error.message,
        action,
      }
    case "connection":
      return {
        title: "Connection interrupted",
        message: CONNECTION_MESSAGE,
        action,
      }
    case "http":
      break
  }

  switch (error.status) {
    case 403:
      return {
        title: "You don't have access",
        message: error.message,
      }
    case 404:
      return {
        title: "Not found",
        message: error.message,
      }
    case 409:
      return {
        title: "Conflict",
        message: error.message,
        action,
      }
    case 429:
      return {
        title: "Slow down",
        message:
          error.retryAfterSeconds !== undefined
            ? `Too many requests. Try again in about ${error.retryAfterSeconds} seconds.`
            : "Too many requests. Wait a moment, then try again.",
      }
  }

  const status = error.status ?? 0

  if (status >= 500) {
    return {
      title: "Something went wrong",
      message: error.requestId
        ? `${error.message} Request ${error.requestId}.`
        : error.message,
      action,
    }
  }

  return {
    title: "API check was not accepted",
    message: error.requestId
      ? `${error.message} Request ${error.requestId}.`
      : error.message,
    action,
  }
}
