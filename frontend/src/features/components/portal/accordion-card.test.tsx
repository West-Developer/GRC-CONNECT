import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, describe, expect, it, vi } from "vitest"

import { AccordionCard } from "@/features/components/portal/accordion-card"

function stubViewport(isPhone: boolean) {
  vi.spyOn(window, "matchMedia").mockImplementation(
    (query: string) =>
      ({
        matches: query.includes("max-width: 47.99rem")
          ? isPhone
          : query.includes("prefers-reduced-motion"),
        media: query,
        onchange: null,
        addListener: () => undefined,
        removeListener: () => undefined,
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
        dispatchEvent: () => false,
      }) as MediaQueryList,
  )
}

function heading() {
  return screen.getByRole("button", { name: "Request history" })
}

describe("AccordionCard", () => {
  afterEach(() => vi.restoreAllMocks())

  it("starts open by default", () => {
    render(
      <AccordionCard id="history" title="Request history">
        <p>Three requests</p>
      </AccordionCard>,
    )

    expect(heading()).toHaveAttribute("aria-expanded", "true")
    expect(screen.getByText("Three requests")).toBeInTheDocument()
  })

  it("respects defaultOpen={false}", () => {
    render(
      <AccordionCard id="history" title="Request history" defaultOpen={false}>
        <p>Three requests</p>
      </AccordionCard>,
    )

    expect(heading()).toHaveAttribute("aria-expanded", "false")
    expect(screen.queryByText("Three requests")).not.toBeInTheDocument()
  })

  describe("collapseOnMobile (stakeholder Doc 13: not everything at once)", () => {
    it("starts collapsed on a phone", () => {
      stubViewport(true)
      render(
        <AccordionCard id="history" title="Request history" collapseOnMobile>
          <p>Three requests</p>
        </AccordionCard>,
      )

      expect(heading()).toHaveAttribute("aria-expanded", "false")
      expect(screen.queryByText("Three requests")).not.toBeInTheDocument()
    })

    it("still opens with one tap on a phone", async () => {
      stubViewport(true)
      render(
        <AccordionCard id="history" title="Request history" collapseOnMobile>
          <p>Three requests</p>
        </AccordionCard>,
      )

      await userEvent.setup().click(heading())

      expect(screen.getByText("Three requests")).toBeInTheDocument()
    })

    it("stays open on a wide screen", () => {
      stubViewport(false)
      render(
        <AccordionCard id="history" title="Request history" collapseOnMobile>
          <p>Three requests</p>
        </AccordionCard>,
      )

      expect(heading()).toHaveAttribute("aria-expanded", "true")
    })

    it("does not open a card that was asked to start closed", () => {
      stubViewport(false)
      render(
        <AccordionCard
          id="history"
          title="Request history"
          defaultOpen={false}
          collapseOnMobile
        >
          <p>Three requests</p>
        </AccordionCard>,
      )

      expect(heading()).toHaveAttribute("aria-expanded", "false")
    })
  })
})
