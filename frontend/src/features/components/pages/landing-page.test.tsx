import { screen, within } from "@testing-library/react"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { LandingPage } from "@/features/components/pages/landing-page"
import { renderWithSession } from "@/tests/render-app"

describe("LandingPage", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    fetchMock.mockImplementation(() => new Promise<Response>(() => undefined))
    vi.stubGlobal("fetch", fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it("connects the official GRC identity to the enrollment portal", async () => {
    renderWithSession(<LandingPage />)

    expect(
      await screen.findByRole("heading", {
        name: "Your GRC enrollment journey starts here.",
      }),
    ).toBeInTheDocument()
    const banner = screen.getByRole("banner")
    expect(within(banner).getByRole("img", { name: "Global Reciprocal Colleges" })).toBeInTheDocument()
    expect(screen.getByText("TOUCHING HEARTS, RENEWING MINDS, TRANSFORMING LIVES")).toBeInTheDocument()

    for (const link of screen.getAllByRole("link", {
      name: "Sign in to portal",
    })) {
      expect(link).toHaveAttribute("href", "/login")
    }

    const navigation = within(banner).getByRole("navigation", {
      name: "Public navigation",
    })
    expect(within(navigation).getByRole("link", { name: "About GRC" })).toHaveAttribute("href", "#about-grc")
    expect(within(navigation).getByRole("link", { name: "Academics" })).toHaveAttribute("href", "#academics")
    expect(within(navigation).getByRole("link", { name: "Student Services" })).toHaveAttribute("href", "#student-services")
    expect(within(navigation).getByRole("link", { name: "Visit GRC Website" })).toHaveAttribute("href", "https://grc.edu.ph/")
  })

  it("offers no account-setup entry point: setup is reachable only through the emailed link", () => {
    const { container } = renderWithSession(<LandingPage />)

    expect(
      screen.queryByRole("link", { name: /account setup/i }),
    ).not.toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: /account setup/i }),
    ).not.toBeInTheDocument()
    expect(container.querySelector('a[href*="account-setup"]')).toBeNull()
  })

  it("presents GRC values and official public pathways without private records", () => {
    renderWithSession(<LandingPage />)

    const about = screen.getByRole("region", {
      name: "About Global Reciprocal Colleges",
    })
    expect(within(about).getByRole("heading", { name: "Vision" })).toBeInTheDocument()
    expect(within(about).getByText("A global community of excellent individuals with values.")).toBeInTheDocument()
    expect(within(about).getByRole("heading", { name: "Mission" })).toBeInTheDocument()

    const services = screen.getByRole("region", { name: "Student services" })
    expect(within(services).getByRole("link", { name: "Visit Admissions" })).toHaveAttribute("href", "https://grc.edu.ph/grc-admission/")
    expect(within(services).getByRole("link", { name: "Visit GRC Library" })).toHaveAttribute("href", "https://grc.edu.ph/grc-library/")
    expect(within(services).getByRole("link", { name: "Visit Scholarship" })).toHaveAttribute("href", "https://grc.edu.ph/grc-scholarship/")
    expect(document.body).not.toHaveTextContent(/student number|private student records/i)
  })

  it("shows the four-stage enrollment journey in order", () => {
    renderWithSession(<LandingPage />)

    const journey = screen.getByRole("region", {
      name: "How enrollment works",
    })
    const steps = within(journey).getAllByRole("listitem")

    expect(steps).toHaveLength(4)
    expect(steps[0]).toHaveTextContent("Schedule preparation")
    expect(steps[1]).toHaveTextContent(
      "Student subject selection and submission",
    )
    expect(steps[2]).toHaveTextContent("Authorized review and approval")
    expect(steps[3]).toHaveTextContent(
      "Payment confirmation and COR finalization",
    )
  })

  it("keeps the landing page focused on enrollment instead of API diagnostics", () => {
    renderWithSession(<LandingPage />)

    expect(screen.queryByRole("region", { name: "System readiness" })).not.toBeInTheDocument()
    expect(screen.queryByText("Demo interface—no private student records are loaded.")).not.toBeInTheDocument()
    expect(screen.queryByText("Contacting the public gateway…")).not.toBeInTheDocument()
  })
})
