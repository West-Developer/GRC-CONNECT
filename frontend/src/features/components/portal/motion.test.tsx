import { render, screen } from "@testing-library/react"
import { afterEach, describe, expect, it, vi } from "vitest"

import {
  FadePresence,
  Reveal,
  StaggerItem,
  StaggerList,
} from "@/features/components/portal/motion"

describe("motion primitives", () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it("Reveal renders its children", () => {
    render(<Reveal>Section content</Reveal>)

    expect(screen.getByText("Section content")).toBeInTheDocument()
  })

  it("StaggerList/StaggerItem render every item", () => {
    render(
      <StaggerList>
        <StaggerItem>First</StaggerItem>
        <StaggerItem>Second</StaggerItem>
      </StaggerList>,
    )

    expect(screen.getByText("First")).toBeInTheDocument()
    expect(screen.getByText("Second")).toBeInTheDocument()
  })

  it("FadePresence renders the content for the current key", () => {
    render(
      <FadePresence presenceKey="loading">
        <p>Loading…</p>
      </FadePresence>,
    )

    expect(screen.getByText("Loading…")).toBeInTheDocument()
  })

  it("skips animation and renders a plain wrapper when the user prefers reduced motion", () => {
    vi.stubGlobal(
      "matchMedia",
      (query: string) =>
        ({
          matches: query.includes("prefers-reduced-motion"),
          media: query,
          onchange: null,
          addListener: () => undefined,
          removeListener: () => undefined,
          addEventListener: () => undefined,
          removeEventListener: () => undefined,
          dispatchEvent: () => false,
        }) as MediaQueryList,
    )

    render(
      <Reveal className="reduced-motion-check">Reduced motion content</Reveal>,
    )

    const wrapper = screen.getByText("Reduced motion content")
    expect(wrapper).toHaveClass("reduced-motion-check")
    expect(wrapper.tagName).toBe("DIV")
  })
})
