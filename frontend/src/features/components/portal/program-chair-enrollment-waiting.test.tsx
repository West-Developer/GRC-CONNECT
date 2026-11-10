import { screen } from "@testing-library/react"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { ProgramChairEnrollmentWorkspace } from "@/features/components/portal/program-chair-enrollment-workspace"
import { renderWithSession } from "@/tests/render-app"

describe("Program Chair enrollment startup state", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it("waits for Registrar when no actionable academic term exists", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), { status: 200 }),
    )

    renderWithSession(<ProgramChairEnrollmentWorkspace />, {
      session: {
        userId: "4",
        displayName: "CCS Chair",
        role: "program_chair",
        college: "ccs",
        signedInAt: "2026-08-02T00:00:00Z",
      },
    })

    expect(
      await screen.findByText(
        /Waiting for Registrar for the school year and semester\./,
      ),
    ).toBeInTheDocument()
  })

  // Stakeholder Doc 18: the old semester is still sitting as
  // `semester_ongoing` (the Registrar has not archived it) and its own
  // enrollment window has already closed, with no new draft/for_dean_approval
  // term started yet — the Program Chair's view must clear to the waiting
  // state instead of continuing to show the stale, enrollment-closed term.
  it("clears to the waiting state when the unarchived term's enrollment window has already closed and no new term has started", async () => {
    fetchMock.mockImplementation((input: RequestInfo | URL) => {
      const target =
        typeof input === "string"
          ? input
          : input instanceof URL
            ? input.toString()
            : input.url

      if (target.includes("/academic-terms")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [
                {
                  type: "academic-term",
                  id: 2,
                  school_year: "2026-2027",
                  semester: "1st",
                  starts_at: null,
                  ends_at: null,
                  enrollment_opens_at: null,
                  enrollment_closes_at: "2026-01-01T00:00:00Z",
                  add_drop_deadline_at: null,
                  grading_deadline_at: null,
                  status: "semester_ongoing",
                  status_label: "Semester Ongoing",
                },
              ],
            }),
            { status: 200 },
          ),
        )
      }

      return Promise.resolve(
        new Response(JSON.stringify({ data: [] }), { status: 200 }),
      )
    })

    renderWithSession(<ProgramChairEnrollmentWorkspace />, {
      session: {
        userId: "4",
        displayName: "CCS Chair",
        role: "program_chair",
        college: "ccs",
        signedInAt: "2026-08-02T00:00:00Z",
      },
    })

    expect(
      await screen.findByText(
        /Waiting for Registrar for the school year and semester\./,
      ),
    ).toBeInTheDocument()
  })
})
