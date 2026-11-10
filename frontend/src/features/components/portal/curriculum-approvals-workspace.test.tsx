import { screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { describe, expect, it, vi, beforeEach, afterEach } from "vitest"

import { CurriculumApprovalsWorkspace } from "@/features/components/portal/curriculum-approvals-workspace"
import { renderWithSession } from "@/tests/render-app"

const fetchMock = vi.fn<typeof fetch>()
beforeEach(() => vi.stubGlobal("fetch", fetchMock))
afterEach(() => vi.unstubAllGlobals())

function requestUrl(input: RequestInfo | URL): string {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

const programs = [
  {
    type: "program",
    id: 1,
    code: "BSCS",
    name: "BS Computer Science",
    status: "active",
    status_label: "Active",
  },
]

function pendingCurriculum(status: string) {
  return {
    type: "curriculum",
    id: 1,
    program_id: 1,
    name: "BSCS Curriculum 2026-2027",
    effective_school_year: "2026-2027",
    status,
    status_label:
      status === "pending_dean_review"
        ? "Pending Dean Review"
        : "Pending Executive Review",
    decided_at: "2026-08-07T00:00:00Z",
    last_decision_reason: null,
    subjects: [
      {
        subject_id: 1,
        code: "CS101",
        title: "Programming 1",
        units: 3,
        year_level: 1,
        semester: "1st",
        is_required: true,
        prerequisites: [],
      },
    ],
  }
}

function mockList(status: string, transitionResponseStatus: string) {
  fetchMock.mockImplementation((input) => {
    const url = requestUrl(input)
    if (url.includes("/programs")) {
      return Promise.resolve(new Response(JSON.stringify({ data: programs })))
    }
    if (url.endsWith("/transition")) {
      return Promise.resolve(
        new Response(
          JSON.stringify({ data: pendingCurriculum(transitionResponseStatus) }),
        ),
      )
    }
    if (url.includes("/curricula")) {
      return Promise.resolve(
        new Response(JSON.stringify({ data: [pendingCurriculum(status)] })),
      )
    }
    return Promise.resolve(new Response(JSON.stringify({ data: [] })))
  })
}

describe("CurriculumApprovalsWorkspace", () => {
  it("lists curricula pending the Dean's review and approves one, advancing it to Pending Executive Review", async () => {
    mockList("pending_dean_review", "pending_executive_review")
    const user = userEvent.setup()
    renderWithSession(<CurriculumApprovalsWorkspace />, {
      session: {
        userId: "1",
        displayName: "Dean Test",
        role: "dean",
        college: null,
        signedInAt: "2026-08-07T00:00:00.000Z",
      },
    })

    expect(
      await screen.findByText("BSCS Curriculum 2026-2027"),
    ).toBeInTheDocument()

    await user.click(screen.getByRole("button", { name: "Review" }))
    await user.click(await screen.findByRole("button", { name: "Approve" }))

    await screen.findByText(/no curricula are pending your review/i)
  })

  it("uses a filter-free read-only preview while the Dean reviews a curriculum", async () => {
    mockList("pending_dean_review", "pending_executive_review")
    const user = userEvent.setup()
    renderWithSession(<CurriculumApprovalsWorkspace />, {
      session: {
        userId: "1",
        displayName: "Dean Test",
        role: "dean",
        college: "ccs",
        signedInAt: "2026-08-07T00:00:00.000Z",
      },
    })

    await user.click(await screen.findByRole("button", { name: "Review" }))

    expect(screen.queryByLabelText("Program")).not.toBeInTheDocument()
    expect(screen.queryByLabelText("Curriculum")).not.toBeInTheDocument()
    expect(screen.queryByLabelText("Year level")).not.toBeInTheDocument()
    expect(screen.queryByLabelText("Semester")).not.toBeInTheDocument()
    expect(
      await screen.findByText("1st Year · 1st Semester"),
    ).toBeInTheDocument()
  })

  it("requires a reason to return a curriculum and shows it after returning", async () => {
    mockList("pending_dean_review", "draft")
    const user = userEvent.setup()
    renderWithSession(<CurriculumApprovalsWorkspace />, {
      session: {
        userId: "1",
        displayName: "Dean Test",
        role: "dean",
        college: null,
        signedInAt: "2026-08-07T00:00:00.000Z",
      },
    })

    await user.click(await screen.findByRole("button", { name: "Review" }))
    await user.click(
      await screen.findByRole("button", { name: "Return with notes" }),
    )
    await user.click(screen.getByRole("button", { name: "Confirm return" }))

    expect(screen.getByText(/reason is required/i)).toBeInTheDocument()

    await user.type(
      screen.getByLabelText(/notes for program head/i),
      "Missing PATHFIT 2.",
    )
    await user.click(screen.getByRole("button", { name: "Confirm return" }))

    await screen.findByText(/no curricula are pending your review/i)
  })

  it("shows an empty state when nothing is pending", async () => {
    fetchMock.mockImplementation((input) => {
      const url = requestUrl(input)
      if (url.includes("/programs"))
        return Promise.resolve(new Response(JSON.stringify({ data: programs })))
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
    renderWithSession(<CurriculumApprovalsWorkspace />, {
      session: {
        userId: "2",
        displayName: "Exec Test",
        role: "executive_director",
        college: null,
        signedInAt: "2026-08-07T00:00:00.000Z",
      },
    })

    expect(
      await screen.findByText(/no curricula are pending your review/i),
    ).toBeInTheDocument()
  })
})
