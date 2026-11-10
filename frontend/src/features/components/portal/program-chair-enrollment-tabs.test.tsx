import { screen, within } from "@testing-library/react"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { ProgramChairEnrollmentTabs } from "@/features/components/portal/program-chair-enrollment-tabs"
import { renderWithSession } from "@/tests/render-app"

const chairSession = {
  userId: "12",
  displayName: "Program Head",
  role: "program_chair",
  college: "ccs",
  signedInAt: "2026-10-23T00:00:00Z",
} as const

function countsResponse(irregular: number, credits: number) {
  return new Response(
    JSON.stringify({
      data: {
        type: "program_chair_review_counts",
        irregular_advising: irregular,
        credit_mappings: credits,
      },
    }),
  )
}

describe("ProgramChairEnrollmentTabs", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => {
    vi.unstubAllGlobals()
    fetchMock.mockReset()
  })

  it("links the three parts of the Enrollment page and marks the current one", () => {
    fetchMock.mockImplementation(() => Promise.resolve(countsResponse(0, 0)))
    renderWithSession(
      <ProgramChairEnrollmentTabs current="irregular-advising" />,
      {
        session: chairSession,
      },
    )

    const nav = screen.getByRole("navigation", { name: "Enrollment sections" })
    expect(
      within(nav).getByRole("link", { name: "Enrollment" }),
    ).toHaveAttribute("href", "/portal/program-chair-enrollment")
    expect(
      within(nav).getByRole("link", { name: "Irregular Advising" }),
    ).toHaveAttribute("href", "/portal/irregular-enrollments")
    expect(
      within(nav).getByRole("link", { name: "Credit Mappings" }),
    ).toHaveAttribute("href", "/portal/credit-mappings")
    expect(
      within(nav).getByRole("link", { name: "Irregular Advising" }),
    ).toHaveAttribute("aria-current", "page")
    expect(
      within(nav).getByRole("link", { name: "Enrollment" }),
    ).not.toHaveAttribute("aria-current")
  })

  it("shows a number above Irregular Advising and Credit Mappings, each its own", async () => {
    fetchMock.mockImplementation(() => Promise.resolve(countsResponse(4, 12)))
    renderWithSession(<ProgramChairEnrollmentTabs current="enrollment" />, {
      session: chairSession,
    })

    expect(
      await screen.findByTestId("tab-count-irregular-advising"),
    ).toHaveTextContent("4")
    expect(screen.getByTestId("tab-count-credit-mappings")).toHaveTextContent(
      "12",
    )
    expect(screen.queryByTestId("tab-count-enrollment")).not.toBeInTheDocument()
    // The number is also in the link's name, so a screen reader hears it.
    expect(
      screen.getByRole("link", {
        name: "Credit Mappings, 12 waiting for your review",
      }),
    ).toBeInTheDocument()
  })

  it("shows no number when nothing is waiting, and caps a long one", async () => {
    fetchMock.mockImplementation(() => Promise.resolve(countsResponse(0, 140)))
    renderWithSession(<ProgramChairEnrollmentTabs current="enrollment" />, {
      session: chairSession,
    })

    expect(
      await screen.findByTestId("tab-count-credit-mappings"),
    ).toHaveTextContent("99+")
    expect(
      screen.queryByTestId("tab-count-irregular-advising"),
    ).not.toBeInTheDocument()
  })

  it("renders nothing, and asks for nothing, for any other role", () => {
    renderWithSession(<ProgramChairEnrollmentTabs current="enrollment" />, {
      session: { ...chairSession, role: "registrar_head" },
    })

    expect(
      screen.queryByRole("navigation", { name: "Enrollment sections" }),
    ).not.toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("has no detectable accessibility violations", async () => {
    fetchMock.mockImplementation(() => Promise.resolve(countsResponse(1, 2)))
    const { container } = renderWithSession(
      <ProgramChairEnrollmentTabs current="credit-mappings" />,
      { session: chairSession },
    )
    await screen.findByTestId("tab-count-credit-mappings")

    expect(await axe(container)).toHaveNoViolations()
  })
})
