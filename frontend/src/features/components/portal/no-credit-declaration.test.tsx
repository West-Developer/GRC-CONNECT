import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { ProgramChairCreditMappingsWorkspace } from "@/features/components/portal/program-chair-credit-mappings-workspace"
import { StudentCreditMappingDialog } from "@/features/components/portal/student-credit-mapping-dialog"
import { renderWithSession } from "@/tests/render-app"

const studentSession = {
  userId: "4",
  displayName: "Test Student",
  role: "student",
  signedInAt: "2026-10-23T00:00:00Z",
} as const

const chairSession = {
  userId: "12",
  displayName: "Program Head",
  role: "program_chair",
  college: "ccs",
  signedInAt: "2026-10-23T00:00:00Z",
} as const

function declaration(overrides: Record<string, unknown> = {}) {
  return {
    type: "no_credit_declaration",
    id: 3,
    student_id: 4,
    student_number: "2026-10-00007",
    student_name: "Denmar Curtivo",
    status: "pending",
    declared_at: "2026-10-23T01:00:00Z",
    confirmed_at: null,
    ...overrides,
  }
}

function emptyPage() {
  return {
    data: [],
    links: { first: "http://x/1", last: "http://x/1", prev: null, next: null },
    meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 },
  }
}

function pathOf(input: RequestInfo | URL): string {
  const raw =
    typeof input === "string"
      ? input
      : input instanceof URL
        ? input.toString()
        : input.url
  return new URL(raw, "http://localhost").pathname
}

describe("no subject to credit", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => {
    vi.unstubAllGlobals()
    fetchMock.mockReset()
  })

  describe("on the student's credit mapping dialog", () => {
    function route(options: {
      declaration?: ReturnType<typeof declaration> | null
      credits?: unknown[]
    }) {
      const posts: string[] = []
      fetchMock.mockImplementation((input, init) => {
        const path = pathOf(input)
        const method = init?.method ?? "GET"
        if (path.endsWith("/no-credit-declaration") && method === "POST") {
          posts.push(path)
          return Promise.resolve(
            new Response(JSON.stringify({ data: declaration() }), {
              status: 201,
            }),
          )
        }
        if (path.endsWith("/no-credit-declaration")) {
          return Promise.resolve(
            new Response(JSON.stringify({ data: options.declaration ?? null })),
          )
        }
        if (path.endsWith("/transferee-credits")) {
          return Promise.resolve(
            new Response(
              JSON.stringify({
                ...emptyPage(),
                data: options.credits ?? [],
              }),
            ),
          )
        }
        return Promise.resolve(new Response(JSON.stringify({ data: [] })))
      })
      return posts
    }

    async function openDialog(user: ReturnType<typeof userEvent.setup>) {
      await user.click(
        screen.getByRole("button", { name: /Request Credit Mapping/i }),
      )
      await screen.findByRole("heading", { name: "Transferee Credit Mapping" })
    }

    it("lets a student with no credits tell the Program Head they have nothing to credit", async () => {
      const user = userEvent.setup()
      const posts = route({})
      renderWithSession(<StudentCreditMappingDialog />, {
        session: studentSession,
      })
      await openDialog(user)

      await user.click(
        await screen.findByRole("button", {
          name: "I have no subject to credit",
        }),
      )

      await waitFor(() => expect(posts).toHaveLength(1))
      expect(
        await screen.findByText(
          /Your Program Head was told you have no subject to credit/,
        ),
      ).toBeInTheDocument()
    })

    it("shows that it is waiting for the Program Head, with no button to send it again", async () => {
      const user = userEvent.setup()
      route({ declaration: declaration() })
      renderWithSession(<StudentCreditMappingDialog />, {
        session: studentSession,
      })
      await openDialog(user)

      expect(
        await screen.findByText("Awaiting Program Head"),
      ).toBeInTheDocument()
      expect(
        screen.queryByRole("button", { name: "I have no subject to credit" }),
      ).not.toBeInTheDocument()
    })

    it("shows that the Program Head confirmed it", async () => {
      const user = userEvent.setup()
      route({
        declaration: declaration({
          status: "confirmed",
          confirmed_at: "2026-10-23T02:00:00Z",
        }),
      })
      renderWithSession(<StudentCreditMappingDialog />, {
        session: studentSession,
      })
      await openDialog(user)

      expect(await screen.findByText("Confirmed")).toBeInTheDocument()
      expect(screen.getByText(/You can go on to enroll/)).toBeInTheDocument()
    })

    it("still offers it to a student whose every credit was rejected by the Program Head", async () => {
      const user = userEvent.setup()
      route({
        credits: [
          {
            type: "transferee_credit",
            id: 1,
            student_id: 4,
            student_number: "2026-10-00007",
            source_institution: "Other University",
            source_subject_code: "N/A",
            source_subject_title: "N/A",
            source_grade: "0",
            credited_units: 3,
            source_school_year: "2014",
            source_semester: "2026",
            subject_id: null,
            subject_code: null,
            status: "rejected",
            status_label: "Rejected",
            processed_at: null,
            created_at: "2026-10-01T00:00:00Z",
          },
        ],
      })
      renderWithSession(<StudentCreditMappingDialog />, {
        session: studentSession,
      })
      await openDialog(user)

      expect(
        await screen.findByRole("button", {
          name: "I have no subject to credit",
        }),
      ).toBeInTheDocument()
    })

    it("does not offer it to a student who already has subjects in their list", async () => {
      const user = userEvent.setup()
      route({
        credits: [
          {
            type: "transferee_credit",
            id: 1,
            student_id: 4,
            student_number: "2026-10-00007",
            source_institution: "Other University",
            source_subject_code: "CS101",
            source_subject_title: "Computer Concepts",
            source_grade: "1.50",
            credited_units: 3,
            source_school_year: "2023-2024",
            source_semester: "1st",
            subject_id: null,
            subject_code: null,
            status: "pending",
            status_label: "Pending",
            processed_at: null,
            created_at: "2026-10-01T00:00:00Z",
          },
        ],
      })
      renderWithSession(<StudentCreditMappingDialog />, {
        session: studentSession,
      })
      await openDialog(user)

      expect(
        await screen.findByText(/CS101 — Computer Concepts/),
      ).toBeInTheDocument()
      expect(
        screen.queryByRole("heading", { name: "Nothing to credit?" }),
      ).not.toBeInTheDocument()
    })
  })

  describe("on the Program Head's Credit Mappings page", () => {
    it("lists the students with nothing to credit and confirms one", async () => {
      const user = userEvent.setup()
      const confirmed: string[] = []
      fetchMock.mockImplementation((input, init) => {
        const path = pathOf(input)
        const method = init?.method ?? "GET"
        if (path.endsWith("/confirm") && method === "POST") {
          confirmed.push(path)
          return Promise.resolve(
            new Response(
              JSON.stringify({
                data: declaration({
                  status: "confirmed",
                  confirmed_at: "2026-10-23T02:00:00Z",
                }),
              }),
            ),
          )
        }
        if (path.endsWith("/no-credit-declarations")) {
          return Promise.resolve(
            new Response(JSON.stringify({ data: [declaration()] })),
          )
        }
        if (
          path.endsWith("/transferee-credits") ||
          path.endsWith("/tor-documents")
        ) {
          return Promise.resolve(new Response(JSON.stringify(emptyPage())))
        }
        return Promise.resolve(new Response(JSON.stringify({ data: [] })))
      })
      renderWithSession(<ProgramChairCreditMappingsWorkspace />, {
        session: chairSession,
      })

      const table = await screen.findByRole("table", {
        name: "Students with no subject to credit",
      })
      expect(
        within(table).getByText("Denmar Curtivo (2026-10-00007)"),
      ).toBeInTheDocument()

      await user.click(
        within(table).getByRole("button", {
          name: "Confirm that Denmar Curtivo has no subject to credit",
        }),
      )

      await waitFor(() =>
        expect(confirmed).toEqual(["/api/v1/no-credit-declarations/3/confirm"]),
      )
      expect(
        await screen.findByText(
          /Confirmed that Denmar Curtivo has no subject to credit/,
        ),
      ).toBeInTheDocument()
    })

    it("says so when nobody is waiting", async () => {
      fetchMock.mockImplementation((input) => {
        const path = pathOf(input)
        if (
          path.endsWith("/transferee-credits") ||
          path.endsWith("/tor-documents")
        ) {
          return Promise.resolve(new Response(JSON.stringify(emptyPage())))
        }
        return Promise.resolve(new Response(JSON.stringify({ data: [] })))
      })
      renderWithSession(<ProgramChairCreditMappingsWorkspace />, {
        session: chairSession,
      })

      expect(
        await screen.findByText(
          "No student is waiting for you to confirm that they have nothing to credit.",
        ),
      ).toBeInTheDocument()
    })
  })
})
