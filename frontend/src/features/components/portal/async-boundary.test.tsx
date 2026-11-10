import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { describe, expect, it, vi } from "vitest"

import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { ApiClientError } from "@/features/services/api-client"

describe("AsyncBoundary", () => {
  it("announces a single loading status while pending", () => {
    render(
      <AsyncBoundary
        query={{
          isPending: true,
          isError: false,
          error: null,
          data: undefined,
          refetch: () => undefined,
        }}
      >
        {() => <p>data</p>}
      </AsyncBoundary>,
    )

    expect(screen.getByRole("status")).toBeInTheDocument()
    expect(screen.queryByText("data")).not.toBeInTheDocument()
  })

  it("shows a layout-shaped skeleton, announced once, when pending without a custom fallback", () => {
    // ADR 0029: in-page loads show content-shaped placeholders (perceived
    // performance) instead of a spinner-like logo; the branded logo stays for
    // full-page and session-restore states.
    const { container } = render(
      <AsyncBoundary
        query={{
          isPending: true,
          isError: false,
          error: null,
          data: undefined,
          refetch: () => undefined,
        }}
        loadingLabel="Loading grades…"
      >
        {() => <p>data</p>}
      </AsyncBoundary>,
    )

    const status = screen.getByRole("status")
    expect(status).toHaveTextContent("Loading grades…")
    expect(
      container.querySelectorAll('[data-slot="skeleton"]').length,
    ).toBeGreaterThanOrEqual(3)
    // One status region only; the skeleton blocks stay out of the a11y tree.
    expect(screen.getAllByRole("status")).toHaveLength(1)
    expect(screen.queryByText("GRC")).not.toBeInTheDocument()
  })

  it("preserves a caller-provided loading fallback", () => {
    render(
      <AsyncBoundary
        query={{
          isPending: true,
          isError: false,
          error: null,
          data: undefined,
          refetch: () => undefined,
        }}
        loadingFallback={<p>Loading roster layout</p>}
      >
        {() => <p>data</p>}
      </AsyncBoundary>,
    )

    expect(screen.getByText("Loading roster layout")).toBeInTheDocument()
    expect(screen.queryByText("GRC")).not.toBeInTheDocument()
  })

  it("renders a status-aware error, omitting retry for a non-retryable status", () => {
    const refetch = vi.fn()
    render(
      <AsyncBoundary
        query={{
          isPending: false,
          isError: true,
          error: new ApiClientError({
            kind: "http",
            message: "You are not authorized to view this.",
            status: 403,
          }),
          data: undefined,
          refetch,
        }}
      >
        {() => <p>data</p>}
      </AsyncBoundary>,
    )

    expect(screen.getByText("You don't have access")).toBeInTheDocument()
    // 403 is not retryable — no action button.
    expect(
      screen.queryByRole("button", { name: "Try again" }),
    ).not.toBeInTheDocument()
    expect(refetch).not.toHaveBeenCalled()
  })

  it("shows a retry action for a retryable error and calls refetch on click", async () => {
    const refetch = vi.fn()
    const user = userEvent.setup()
    render(
      <AsyncBoundary
        query={{
          isPending: false,
          isError: true,
          error: new ApiClientError({ kind: "connection", message: "offline" }),
          data: undefined,
          refetch,
        }}
      >
        {() => <p>data</p>}
      </AsyncBoundary>,
    )

    await user.click(screen.getByRole("button", { name: "Try again" }))
    expect(refetch).toHaveBeenCalledOnce()
  })

  it("renders the empty message when isEmpty matches the data", () => {
    render(
      <AsyncBoundary
        query={{
          isPending: false,
          isError: false,
          error: null,
          data: [],
          refetch: () => undefined,
        }}
        isEmpty={(data: unknown[]) => data.length === 0}
        emptyMessage="No students are enrolled in this section yet."
      >
        {() => <p>data</p>}
      </AsyncBoundary>,
    )

    expect(
      screen.getByText("No students are enrolled in this section yet."),
    ).toBeInTheDocument()
    expect(screen.queryByText("data")).not.toBeInTheDocument()
  })

  it("renders children with the resolved data on success", () => {
    render(
      <AsyncBoundary
        query={{
          isPending: false,
          isError: false,
          error: null,
          data: { name: "Section 1A" },
          refetch: () => undefined,
        }}
      >
        {(data) => <p>{data.name}</p>}
      </AsyncBoundary>,
    )

    expect(screen.getByText("Section 1A")).toBeInTheDocument()
  })
})
