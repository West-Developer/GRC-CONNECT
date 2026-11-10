import { screen } from "@testing-library/react"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { InstitutionDashboardWorkspace } from "@/features/components/portal/institution-dashboard-workspace"
import { renderWithSession } from "@/tests/render-app"

const summary = {
  data: {
    type: "institution_summary",
    status_counts: { draft: 0, enrolled: 12, withdrawn: 2 },
    total_programs: 3,
    active_programs: 2,
    total_sections: 10,
    published_sections: 8,
    program_counts: { BSCS: 7, BSIT: 5 },
    year_over_year: [
      { school_year: "2025-2026", enrollment_count: 20 },
      { school_year: "2026-2027", enrollment_count: 14 },
    ],
  },
}

describe("InstitutionDashboardWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("withholds the dashboard from a non-executive-director role", () => {
    renderWithSession(<InstitutionDashboardWorkspace />, {
      session: {
        userId: "5",
        displayName: "Dean",
        role: "dean",
        signedInAt: "2026-07-31T00:00:00Z",
      },
    })
    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("shows institution-wide status, program, and year-over-year counts", async () => {
    fetchMock.mockImplementation(() =>
      Promise.resolve(new Response(JSON.stringify(summary))),
    )
    renderWithSession(<InstitutionDashboardWorkspace />, {
      session: {
        userId: "6",
        displayName: "Executive",
        role: "executive_director",
        signedInAt: "2026-07-31T00:00:00Z",
      },
    })

    expect(await screen.findByText(/Enrolled: 12/)).toBeInTheDocument()
    expect(screen.getByText(/2 of 3/)).toBeInTheDocument()
    expect(screen.getByText(/8 of 10/)).toBeInTheDocument()
    expect(screen.getByText(/BSCS: 7/)).toBeInTheDocument()
    expect(screen.getByText(/2025-2026: 20/)).toBeInTheDocument()
  })

  it("has no detectable accessibility violations once loaded", async () => {
    fetchMock.mockImplementation(() =>
      Promise.resolve(new Response(JSON.stringify(summary))),
    )
    const { container } = renderWithSession(<InstitutionDashboardWorkspace />, {
      session: {
        userId: "6",
        displayName: "Executive",
        role: "executive_director",
        signedInAt: "2026-07-31T00:00:00Z",
      },
    })
    await screen.findByText(/Enrolled: 12/)
    expect(await axe(container)).toHaveNoViolations()
  })

  it("opens enrollment status dialog when status button is clicked", async () => {
    fetchMock.mockImplementation(() =>
      Promise.resolve(new Response(JSON.stringify(summary))),
    )
    renderWithSession(<InstitutionDashboardWorkspace />, {
      session: {
        userId: "6",
        displayName: "Executive",
        role: "executive_director",
        signedInAt: "2026-07-31T00:00:00Z",
      },
    })

    const enrolledBtn = await screen.findByRole("button", {
      name: /Enrolled: 12/,
    })
    enrolledBtn.click()

    expect(
      await screen.findByRole("dialog", { name: "Students · Enrolled" }),
    ).toBeInTheDocument()
  })

  it("opens year-over-year report dialog when View & Print Report is clicked", async () => {
    fetchMock.mockImplementation(() =>
      Promise.resolve(new Response(JSON.stringify(summary))),
    )
    renderWithSession(<InstitutionDashboardWorkspace />, {
      session: {
        userId: "6",
        displayName: "Executive",
        role: "executive_director",
        signedInAt: "2026-07-31T00:00:00Z",
      },
    })

    const reportBtn = await screen.findByRole("button", {
      name: "View & Print Report",
    })
    reportBtn.click()

    expect(
      await screen.findByRole("dialog", {
        name: "Year-over-Year Enrollment Report",
      }),
    ).toBeInTheDocument()
  })
})
