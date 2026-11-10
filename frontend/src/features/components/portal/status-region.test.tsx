import { render, screen } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import { StatusRegion } from "@/features/components/portal/status-region"

describe("StatusRegion", () => {
  it("renders nothing when there is no message", () => {
    const { container } = render(<StatusRegion message={null} />)

    expect(container).toBeEmptyDOMElement()
  })

  it("renders the message in a polite live region", () => {
    render(<StatusRegion message="3 results found." />)

    const status = screen.getByRole("status")
    expect(status).toHaveAttribute("aria-live", "polite")
    expect(status).toHaveTextContent("3 results found.")
  })
})
