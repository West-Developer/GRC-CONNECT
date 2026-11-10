import { screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { StudentCreditMappingDialog } from "@/features/components/portal/student-credit-mapping-dialog"
import { renderWithSession } from "@/tests/render-app"

const studentSession = {
  userId: "4",
  displayName: "Test Student",
  role: "student",
  signedInAt: "2026-07-29T12:00:00Z",
} as const

const sentCredit = {
  type: "transferee_credit",
  id: 1,
  student_id: 4,
  student_number: "2026-0001",
  source_institution: "University of the East",
  source_subject_code: "CS101",
  source_subject_title: "Computer Concepts",
  source_grade: "1.50",
  credited_units: 3,
  source_school_year: "2023-2024",
  source_semester: "1st Semester",
  subject_id: 10,
  subject_code: "CS101",
  status: "pending",
  status_label: "Pending",
  processed_at: null,
  created_at: "2026-08-01T00:00:00Z",
}

const draftCredit = {
  ...sentCredit,
  id: 5,
  source_subject_code: "MATH101",
  source_subject_title: "College Algebra",
  subject_id: null,
  subject_code: null,
  status: "draft",
  status_label: "Draft",
}

function paginated(credits: unknown[]) {
  return {
    data: credits,
    links: {
      first: "http://localhost/api/v1/transferee-credits?page=1",
      last: "http://localhost/api/v1/transferee-credits?page=1",
      prev: null,
      next: null,
    },
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 50,
      total: credits.length,
    },
  }
}

function mockFetch(credits: unknown[] = [sentCredit]) {
  return vi.fn<typeof fetch>().mockImplementation((input, init) => {
    const url =
      typeof input === "string"
        ? input
        : input instanceof URL
          ? input.toString()
          : input.url
    const method = init?.method ?? "GET"
    const path = new URL(url, "http://localhost").pathname

    if (path.endsWith("/transferee-credits/submit") && method === "POST") {
      return Promise.resolve(
        new Response(
          JSON.stringify({ data: [{ ...draftCredit, status: "pending" }] }),
        ),
      )
    }
    if (path.endsWith("/transferee-credits") && method === "GET") {
      return Promise.resolve(new Response(JSON.stringify(paginated(credits))))
    }
    if (path.endsWith("/transferee-credits") && method === "POST") {
      return Promise.resolve(
        new Response(JSON.stringify({ data: { ...draftCredit, id: 9 } }), {
          status: 201,
        }),
      )
    }
    if (/\/transferee-credits\/\d+$/.test(path) && method === "PATCH") {
      return Promise.resolve(
        new Response(JSON.stringify({ data: draftCredit })),
      )
    }
    if (/\/transferee-credits\/\d+$/.test(path) && method === "DELETE") {
      return Promise.resolve(new Response(null, { status: 204 }))
    }

    return Promise.resolve(new Response(JSON.stringify({ data: [] })))
  })
}

async function openDialog(user: ReturnType<typeof userEvent.setup>) {
  await user.click(
    screen.getByRole("button", { name: /Request Credit Mapping/i }),
  )
  await screen.findByRole("heading", { name: "Transferee Credit Mapping" })
}

describe("StudentCreditMappingDialog", () => {
  afterEach(() => vi.unstubAllGlobals())

  it("renders the trigger, opens, and lists the credits that were already sent", async () => {
    const user = userEvent.setup()
    vi.stubGlobal("fetch", mockFetch())
    renderWithSession(<StudentCreditMappingDialog />, {
      session: studentSession,
    })

    await openDialog(user)

    expect(screen.getByLabelText(/Previous School/i)).toBeInTheDocument()
    expect(screen.getByLabelText(/Previous Subject Code/i)).toBeInTheDocument()
    expect(screen.getByLabelText(/Subject Title/i)).toBeInTheDocument()
    expect(
      screen.getByRole("heading", { name: /Your Transcript of Records/i }),
    ).toBeInTheDocument()
    expect(
      await screen.findByText(/Your Credited & Pending Subjects/i),
    ).toBeInTheDocument()
    expect(
      await screen.findByText(/CS101 — Computer Concepts/i),
    ).toBeInTheDocument()
    // Nothing is saved yet, so there is no list to send.
    expect(
      screen.queryByRole("button", { name: "Submit to Program Head" }),
    ).not.toBeInTheDocument()
  })

  it("adds a subject to the student's own list without sending it", async () => {
    const user = userEvent.setup()
    const fetchFn = mockFetch([])
    vi.stubGlobal("fetch", fetchFn)
    renderWithSession(<StudentCreditMappingDialog />, {
      session: studentSession,
    })
    await openDialog(user)

    await user.type(screen.getByLabelText(/Previous School/i), "FEU Tech")
    await user.type(screen.getByLabelText(/Previous Subject Code/i), "PROG101")
    await user.type(
      screen.getByLabelText(/Subject Title/i),
      "Computer Programming 1",
    )
    await user.type(screen.getByLabelText(/Grade Obtained/i), "1.25")
    await user.clear(screen.getByLabelText("Units"))
    await user.type(screen.getByLabelText("Units"), "1.5")
    await user.type(screen.getByLabelText("School Year"), "2023-2024")
    await user.type(screen.getByLabelText("Semester"), "1st Semester")

    await user.click(
      screen.getByRole("button", { name: /Add Subject to My List/i }),
    )

    expect(await screen.findByText(/Saved to your list/i)).toBeInTheDocument()
    const post = fetchFn.mock.calls.find(([, init]) => init?.method === "POST")
    expect(JSON.parse(post?.[1]?.body as string)).toEqual({
      source_institution: "FEU Tech",
      source_subject_code: "PROG101",
      source_subject_title: "Computer Programming 1",
      source_grade: "1.25",
      credited_units: 1.5,
      source_school_year: "2023-2024",
      source_semester: "1st Semester",
    })
    // Adding to the list must never send it to the Program Head by itself.
    expect(
      fetchFn.mock.calls.some(([input]) =>
        String(input).includes("/transferee-credits/submit"),
      ),
    ).toBe(false)
  })

  it("does not need a subject code, and asks for the school year and semester the API requires", async () => {
    const user = userEvent.setup()
    const fetchFn = mockFetch([])
    vi.stubGlobal("fetch", fetchFn)
    renderWithSession(<StudentCreditMappingDialog />, {
      session: studentSession,
    })
    await openDialog(user)
    await user.type(screen.getByLabelText(/Previous School/i), "FEU Tech")
    await user.type(
      screen.getByLabelText(/Subject Title/i),
      "Computer Programming 1",
    )

    await user.click(
      screen.getByRole("button", { name: /Add Subject to My List/i }),
    )
    expect(
      await screen.findByText(/enter the school year/i),
    ).toBeInTheDocument()
    expect(fetchFn.mock.calls.some(([, init]) => init?.method === "POST")).toBe(
      false,
    )

    await user.type(screen.getByLabelText("School Year"), "2023-2024")
    await user.click(
      screen.getByRole("button", { name: /Add Subject to My List/i }),
    )
    expect(await screen.findByText(/enter the semester/i)).toBeInTheDocument()

    await user.type(screen.getByLabelText("Semester"), "2nd")
    await user.click(
      screen.getByRole("button", { name: /Add Subject to My List/i }),
    )
    expect(await screen.findByText(/Saved to your list/i)).toBeInTheDocument()
  })

  it("shows the saved list with Edit, Remove and a Submit button, apart from what was already sent", async () => {
    const user = userEvent.setup()
    vi.stubGlobal("fetch", mockFetch([draftCredit, sentCredit]))
    renderWithSession(<StudentCreditMappingDialog />, {
      session: studentSession,
    })
    await openDialog(user)

    expect(
      await screen.findByText(/Your list \(not yet sent\)/i),
    ).toBeInTheDocument()
    expect(screen.getByText(/MATH101 — College Algebra/i)).toBeInTheDocument()
    expect(
      screen.getByRole("button", { name: "Edit College Algebra" }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("button", { name: "Remove College Algebra" }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("button", { name: "Submit to Program Head" }),
    ).toBeInTheDocument()
    expect(screen.getByText(/CS101 — Computer Concepts/i)).toBeInTheDocument()
  })

  it("sends the whole list to the Program Head in one request", async () => {
    const user = userEvent.setup()
    const fetchFn = mockFetch([draftCredit])
    vi.stubGlobal("fetch", fetchFn)
    renderWithSession(<StudentCreditMappingDialog />, {
      session: studentSession,
    })
    await openDialog(user)

    await user.click(
      await screen.findByRole("button", { name: "Submit to Program Head" }),
    )

    expect(
      await screen.findByText(/Your list was sent to your Program Head/i),
    ).toBeInTheDocument()
    const submit = fetchFn.mock.calls.find(([input]) =>
      String(input).endsWith("/transferee-credits/submit"),
    )
    expect(submit?.[1]?.method).toBe("POST")
  })

  it("removes a subject from the list", async () => {
    const user = userEvent.setup()
    const fetchFn = mockFetch([draftCredit])
    vi.stubGlobal("fetch", fetchFn)
    renderWithSession(<StudentCreditMappingDialog />, {
      session: studentSession,
    })
    await openDialog(user)

    await user.click(
      await screen.findByRole("button", { name: "Remove College Algebra" }),
    )

    await vi.waitFor(() => {
      const del = fetchFn.mock.calls.find(
        ([, init]) => init?.method === "DELETE",
      )
      expect(String(del?.[0])).toMatch(/\/api\/v1\/transferee-credits\/5$/)
    })
  })

  it("edits a saved subject and saves the change", async () => {
    const user = userEvent.setup()
    const fetchFn = mockFetch([draftCredit])
    vi.stubGlobal("fetch", fetchFn)
    renderWithSession(<StudentCreditMappingDialog />, {
      session: studentSession,
    })
    await openDialog(user)

    await user.click(
      await screen.findByRole("button", { name: "Edit College Algebra" }),
    )
    const title = screen.getByLabelText(/Subject Title/i)
    expect(title).toHaveValue("College Algebra")
    await user.clear(title)
    await user.type(title, "College Algebra 1")
    await user.click(screen.getByRole("button", { name: "Save Changes" }))

    expect(
      await screen.findByText(/Changes saved to your list/i),
    ).toBeInTheDocument()
    const patch = fetchFn.mock.calls.find(
      ([, init]) => init?.method === "PATCH",
    )
    expect(String(patch?.[0])).toMatch(/\/api\/v1\/transferee-credits\/5$/)
    expect(JSON.parse(patch?.[1]?.body as string)).toMatchObject({
      source_subject_title: "College Algebra 1",
    })
  })

  it("tells the student where each sent request is on its way", async () => {
    const user = userEvent.setup()
    vi.stubGlobal(
      "fetch",
      mockFetch([
        { ...sentCredit, id: 1, status: "pending", status_label: "Pending" },
        {
          ...sentCredit,
          id: 2,
          source_subject_title: "Statistics",
          status: "endorsed",
          status_label: "Endorsed",
        },
      ]),
    )
    renderWithSession(<StudentCreditMappingDialog />, {
      session: studentSession,
    })
    await openDialog(user)

    expect(await screen.findByText("Awaiting Program Head")).toBeInTheDocument()
    expect(screen.getByText("Awaiting Registrar Head")).toBeInTheDocument()
  })

  it("has no detectable accessibility violations when open", async () => {
    const user = userEvent.setup()
    vi.stubGlobal("fetch", mockFetch([draftCredit, sentCredit]))
    const { container } = renderWithSession(<StudentCreditMappingDialog />, {
      session: studentSession,
    })
    await openDialog(user)
    await screen.findByText(/Your list \(not yet sent\)/i)

    expect(await axe(container)).toHaveNoViolations()
  })
})
