import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { RegistrarHeadCreditReviewWorkspace } from "@/features/components/portal/registrar-head-credit-review-workspace"
import { renderWithSession } from "@/tests/render-app"

const headSession = {
  userId: "10",
  displayName: "Registrar Head",
  role: "registrar_head" as const,
  signedInAt: "2026-10-04T00:00:00Z",
}

function requestUrl(input: RequestInfo | URL): string {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

function paginated(entries: unknown[]) {
  return {
    data: entries,
    links: { first: "http://x/1", last: "http://x/1", prev: null, next: null },
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 100,
      total: entries.length,
    },
  }
}

function endorsed(overrides: Record<string, unknown>) {
  return {
    type: "transferee_credit",
    id: 7,
    student_id: 30,
    student_number: "2026-0002",
    student_name: "Maria Santos",
    source_institution: "Technological Institute of the Philippines",
    source_subject_code: "CS 101",
    source_subject_title: "Intro to Computing",
    source_grade: "1.50",
    credited_units: 3,
    source_school_year: "2023-2024",
    source_semester: "1st",
    subject_id: 10,
    subject_code: "CS101",
    subject_title: "Introduction to Computing",
    requested_by_student: true,
    status: "endorsed",
    status_label: "Endorsed",
    endorsed_at: "2026-10-02T00:00:00Z",
    processed_at: null,
    created_at: "2026-10-01T00:00:00Z",
    ...overrides,
  }
}

const mariaFirst = endorsed({ id: 7 })
const mariaSecond = endorsed({
  id: 8,
  source_subject_code: "MAT 101",
  source_subject_title: "College Algebra",
  subject_id: 13,
  subject_code: "MATH101",
  subject_title: "College Algebra",
})
const juan = endorsed({
  id: 9,
  student_id: 31,
  student_number: "2026-0003",
  student_name: "Juan Dela Cruz",
  source_subject_code: "STA 201",
  source_subject_title: "Statistics",
  subject_id: 20,
  subject_code: "MATH201",
  subject_title: "Statistics and Probability",
})

interface Recorded {
  patches: { id: number; body: unknown }[]
  approvals: number
}

function mockRoutes(
  recorded: Recorded,
  overrides: { approveResponse?: () => Response } = {},
) {
  let credits = [mariaFirst, mariaSecond, juan]

  return (input: RequestInfo | URL, init?: RequestInit) => {
    const url = requestUrl(input)
    const method = init?.method ?? "GET"

    if (url.includes("/tor-documents")) {
      return Promise.resolve(new Response(JSON.stringify(paginated([]))))
    }
    if (url.includes("/transferee-credits/approve-all") && method === "POST") {
      recorded.approvals += 1
      if (overrides.approveResponse) {
        return Promise.resolve(overrides.approveResponse())
      }
      const approved = credits
        .filter((row) => row.student_id === 30)
        .map((row) => ({
          ...row,
          status: "approved",
          status_label: "Approved",
        }))
      credits = credits.filter((row) => row.student_id !== 30)
      return Promise.resolve(new Response(JSON.stringify({ data: approved })))
    }
    const patch = /\/transferee-credits\/(\d+)$/.exec(url)
    if (patch && method === "PATCH") {
      const id = Number(patch[1])
      recorded.patches.push({ id, body: JSON.parse(init?.body as string) })
      const row = credits.find((entry) => entry.id === id)!
      credits = credits.filter((entry) => entry.id !== id)
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: { ...row, status: "pending", status_label: "Pending" },
          }),
        ),
      )
    }
    if (url.includes("/transferee-credits") && url.includes("student_id=30")) {
      return Promise.resolve(
        new Response(
          JSON.stringify(
            paginated(credits.filter((row) => row.student_id === 30)),
          ),
        ),
      )
    }
    if (url.includes("/transferee-credits")) {
      return Promise.resolve(new Response(JSON.stringify(paginated(credits))))
    }
    return Promise.resolve(new Response(JSON.stringify({ data: [] })))
  }
}

describe("RegistrarHeadCreditReviewWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  let recorded: Recorded

  beforeEach(() => {
    recorded = { patches: [], approvals: 0 }
    vi.stubGlobal("fetch", fetchMock)
  })
  afterEach(() => {
    fetchMock.mockReset()
    vi.unstubAllGlobals()
  })

  async function openMaria(user: ReturnType<typeof userEvent.setup>) {
    const queue = await screen.findByRole("table", {
      name: "Students waiting for approval",
    })
    await user.click(
      within(queue).getByRole("button", { name: "Review Maria Santos" }),
    )
    return screen.findByRole("list", { name: "Credits to review" })
  }

  it.each(["registrar_staff", "program_chair", "student"] as const)(
    "is not available for a %s",
    (role) => {
      renderWithSession(<RegistrarHeadCreditReviewWorkspace />, {
        session: { ...headSession, role },
      })

      expect(
        screen.getByText("This workspace is not available for your role."),
      ).toBeInTheDocument()
      expect(fetchMock).not.toHaveBeenCalled()
    },
  )

  it("lists the students whose credits the Program Head endorsed, one row per student", async () => {
    fetchMock.mockImplementation(mockRoutes(recorded))
    renderWithSession(<RegistrarHeadCreditReviewWorkspace />, {
      session: headSession,
    })

    const queue = await screen.findByRole("table", {
      name: "Students waiting for approval",
    })
    expect(
      within(queue).getByText("Maria Santos (2026-0002)"),
    ).toBeInTheDocument()
    expect(within(queue).getByText("2 credits")).toBeInTheDocument()
    expect(
      within(queue).getByText("Juan Dela Cruz (2026-0003)"),
    ).toBeInTheDocument()
    expect(within(queue).getByText("1 credit")).toBeInTheDocument()
  })

  it("lists the students of every page of the endorsed queue, not only the first 100 rows", async () => {
    // The API never returns more than 100 rows a page, so a queue read once
    // would hide the students whose rows fall on a later page.
    const requestedPages: number[] = []
    fetchMock.mockImplementation((input) => {
      const url = requestUrl(input)
      if (
        url.includes("/transferee-credits") &&
        url.includes("status=endorsed")
      ) {
        const page = Number(new URL(url, "http://x").searchParams.get("page"))
        requestedPages.push(page)
        return Promise.resolve(
          new Response(
            JSON.stringify({
              ...paginated(page === 1 ? [mariaFirst] : [juan]),
              meta: {
                current_page: page,
                last_page: 2,
                per_page: 100,
                total: 2,
              },
            }),
          ),
        )
      }
      return Promise.resolve(new Response(JSON.stringify(paginated([]))))
    })
    renderWithSession(<RegistrarHeadCreditReviewWorkspace />, {
      session: headSession,
    })

    const queue = await screen.findByRole("table", {
      name: "Students waiting for approval",
    })
    expect(
      await within(queue).findByText("Juan Dela Cruz (2026-0003)"),
    ).toBeInTheDocument()
    expect(
      within(queue).getByText("Maria Santos (2026-0002)"),
    ).toBeInTheDocument()
    expect(requestedPages).toEqual([1, 2])
  })

  it("shows a student's endorsed credits side by side: their entry and what the Program Head mapped it to", async () => {
    fetchMock.mockImplementation(mockRoutes(recorded))
    const user = userEvent.setup()
    renderWithSession(<RegistrarHeadCreditReviewWorkspace />, {
      session: headSession,
    })

    const list = await openMaria(user)
    const rows = within(list).getAllByRole("listitem")

    expect(rows).toHaveLength(2)
    expect(rows[0]).toHaveTextContent("CS 101 — Intro to Computing")
    expect(rows[0]).toHaveTextContent("CS101 — Introduction to Computing")
    expect(rows[0]).toHaveTextContent("3 units")
    expect(rows[1]).toHaveTextContent("MATH101 — College Algebra")
    expect(
      screen.getByRole("button", { name: "Approve all (2)" }),
    ).toBeInTheDocument()
  })

  it("approves all of a student's credits at once and says how many", async () => {
    fetchMock.mockImplementation(mockRoutes(recorded))
    const user = userEvent.setup()
    renderWithSession(<RegistrarHeadCreditReviewWorkspace />, {
      session: headSession,
    })

    await openMaria(user)
    await user.click(screen.getByRole("button", { name: "Approve all (2)" }))

    expect(
      await screen.findByText("Approved 2 credits of Maria Santos."),
    ).toBeInTheDocument()
    expect(recorded.approvals).toBe(1)
    expect(
      screen.queryByRole("list", { name: "Credits to review" }),
    ).not.toBeInTheDocument()
  })

  it("returns one credit to the Program Head only with a reason", async () => {
    fetchMock.mockImplementation(mockRoutes(recorded))
    const user = userEvent.setup()
    renderWithSession(<RegistrarHeadCreditReviewWorkspace />, {
      session: headSession,
    })

    await openMaria(user)
    await user.click(
      screen.getByRole("button", {
        name: "Return CS 101 — Intro to Computing",
      }),
    )
    const confirm = screen.getByRole("button", { name: "Confirm return" })
    expect(confirm).toBeDisabled()
    await user.type(
      screen.getByLabelText("Reason for CS 101 — Intro to Computing"),
      "Map this to CS105 instead.",
    )
    await user.click(confirm)

    await waitFor(() =>
      expect(recorded.patches).toEqual([
        {
          id: 7,
          body: { action: "return", reason: "Map this to CS105 instead." },
        },
      ]),
    )
    // It is no longer waiting for the Head, so the student has one credit left.
    expect(
      await screen.findByRole("button", { name: "Approve all (1)" }),
    ).toBeInTheDocument()
  })

  it("rejects one credit only with a reason", async () => {
    fetchMock.mockImplementation(mockRoutes(recorded))
    const user = userEvent.setup()
    renderWithSession(<RegistrarHeadCreditReviewWorkspace />, {
      session: headSession,
    })

    await openMaria(user)
    await user.click(
      screen.getByRole("button", { name: "Reject MAT 101 — College Algebra" }),
    )
    const confirm = screen.getByRole("button", { name: "Confirm rejection" })
    expect(confirm).toBeDisabled()
    await user.type(
      screen.getByLabelText("Reason for MAT 101 — College Algebra"),
      "Transcript does not show it.",
    )
    await user.click(confirm)

    await waitFor(() =>
      expect(recorded.patches).toEqual([
        {
          id: 8,
          body: { action: "reject", reason: "Transcript does not show it." },
        },
      ]),
    )
  })

  it("shows the API's reason when approving is refused, and stays open", async () => {
    fetchMock.mockImplementation(
      mockRoutes(recorded, {
        approveResponse: () =>
          new Response(
            JSON.stringify({
              error: {
                code: "FORBIDDEN",
                message: "This action is unauthorized.",
                errors: {},
                request_id: "req-1",
              },
            }),
            { status: 403 },
          ),
      }),
    )
    const user = userEvent.setup()
    renderWithSession(<RegistrarHeadCreditReviewWorkspace />, {
      session: headSession,
    })

    await openMaria(user)
    await user.click(screen.getByRole("button", { name: "Approve all (2)" }))

    expect(await screen.findByRole("alert")).toBeInTheDocument()
    expect(
      screen.getByRole("list", { name: "Credits to review" }),
    ).toBeInTheDocument()
  })

  it("has no detectable accessibility violations once loaded", async () => {
    fetchMock.mockImplementation(mockRoutes(recorded))
    const user = userEvent.setup()
    const { container } = renderWithSession(
      <RegistrarHeadCreditReviewWorkspace />,
      { session: headSession },
    )

    await openMaria(user)
    expect(await axe(container)).toHaveNoViolations()
  })
})
