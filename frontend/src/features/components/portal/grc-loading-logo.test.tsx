import { render, screen } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import { GrcLoadingLogo } from "@/features/components/portal/grc-loading-logo"

describe("GrcLoadingLogo", () => {
  it("announces the loading state while keeping the GRC monogram decorative", () => {
    render(<GrcLoadingLogo label="Loading enrollment workspace…" />)

    expect(
      screen.getByRole("status", { name: "Loading enrollment workspace…" }),
    ).toBeInTheDocument()
    expect(screen.getByText("GRC")).toHaveAttribute("aria-hidden", "true")
  })

  it("supports vertical stacked layout with custom size and fullPage container", () => {
    render(
      <GrcLoadingLogo
        layout="vertical"
        size="lg"
        fullPage
        label="Restoring your session…"
      />,
    )

    const statusEl = screen.getByRole("status", {
      name: "Restoring your session…",
    })
    expect(statusEl).toBeInTheDocument()
    expect(statusEl).toHaveClass("flex-col", "text-center", "min-h-svh")
    expect(screen.getByText("Restoring your session…")).toBeInTheDocument()
    expect(screen.getByText("GRC")).toHaveAttribute("aria-hidden", "true")
  })
})
