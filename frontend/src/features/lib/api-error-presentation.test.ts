import { describe, expect, it, vi } from "vitest"
import { z } from "zod"

import { getStatePresentation } from "@/features/lib/api-error-presentation"
import { ApiClientError } from "@/features/services/api-client"

describe("getStatePresentation", () => {
  it("presents a non-ApiClientError as a generic connection failure", () => {
    const presentation = getStatePresentation(new Error("boom"))

    expect(presentation.title).toBe("Connection interrupted")
    expect(presentation.action).toBeUndefined()
  })

  it("presents a schema mismatch as a contract problem, not a lost connection", () => {
    const result = z
      .object({ id: z.number() })
      .strict()
      .safeParse({ id: 1, extra: true })
    if (result.success) throw new Error("expected the payload to fail")

    const presentation = getStatePresentation(result.error)

    expect(presentation.title).toBe("Unexpected API response")
  })

  it("presents a 403 as an authorization error", () => {
    const presentation = getStatePresentation(
      new ApiClientError({
        kind: "http",
        message: "You are not authorized to view this.",
        status: 403,
      }),
    )

    expect(presentation.title).toBe("You don't have access")
    expect(presentation.message).toContain("not authorized")
  })

  it("presents a 404 as a not-found error", () => {
    const presentation = getStatePresentation(
      new ApiClientError({
        kind: "http",
        message: "The requested resource was not found.",
        status: 404,
      }),
    )

    expect(presentation.title).toBe("Not found")
  })

  it("presents a 409 as a conflict, preserving the server's message", () => {
    const presentation = getStatePresentation(
      new ApiClientError({
        kind: "http",
        message: "This record changed since you loaded it.",
        status: 409,
      }),
    )

    expect(presentation.title).toBe("Conflict")
    expect(presentation.message).toBe(
      "This record changed since you loaded it.",
    )
  })

  it("presents a 429 with a specific wait time when Retry-After is present", () => {
    const presentation = getStatePresentation(
      new ApiClientError({
        kind: "http",
        message: "Too many requests.",
        status: 429,
        retryAfterSeconds: 30,
      }),
    )

    expect(presentation.title).toBe("Slow down")
    expect(presentation.message).toContain("30 seconds")
  })

  it("presents a 429 without a wait time when Retry-After is absent", () => {
    const presentation = getStatePresentation(
      new ApiClientError({
        kind: "http",
        message: "Too many requests.",
        status: 429,
      }),
    )

    expect(presentation.title).toBe("Slow down")
    expect(presentation.message).not.toContain("undefined")
  })

  it("presents kind: connection as offline/dependency failure", () => {
    const presentation = getStatePresentation(
      new ApiClientError({
        kind: "connection",
        message: "The public enrollment API could not be reached.",
      }),
    )

    expect(presentation.title).toBe("Connection interrupted")
  })

  it("presents a generic 5xx with the requestId for support", () => {
    const presentation = getStatePresentation(
      new ApiClientError({
        kind: "http",
        message: "Something went wrong on our end.",
        status: 500,
        requestId: "req-999",
      }),
    )

    expect(presentation.title).toBe("Something went wrong")
    expect(presentation.message).toContain("req-999")
  })

  it("presents kind: configuration and kind: contract using the error's own message", () => {
    const configuration = getStatePresentation(
      new ApiClientError({
        kind: "configuration",
        message: "The public API address is not configured correctly.",
      }),
    )
    const contract = getStatePresentation(
      new ApiClientError({
        kind: "contract",
        message: "The public API returned a response that was not valid JSON.",
      }),
    )

    expect(configuration.message).toBe(
      "The public API address is not configured correctly.",
    )
    expect(contract.message).toBe(
      "The public API returned a response that was not valid JSON.",
    )
  })

  it("wires the onRetry callback into the returned action when provided", () => {
    const onRetry = vi.fn()
    const presentation = getStatePresentation(
      new ApiClientError({ kind: "connection", message: "offline" }),
      { onRetry },
    )

    expect(presentation.action?.label).toBe("Try again")
    presentation.action?.onClick()
    expect(onRetry).toHaveBeenCalledOnce()
  })

  it("omits the action when no onRetry callback is provided", () => {
    const presentation = getStatePresentation(
      new ApiClientError({ kind: "connection", message: "offline" }),
    )

    expect(presentation.action).toBeUndefined()
  })
})
