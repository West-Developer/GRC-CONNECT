import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { DeanFacultyLoadWorkspace } from "@/features/components/portal/dean-faculty-load-workspace"
import { renderWithSession } from "@/tests/render-app"

const terms = {
  data: [
    {
      type: "academic-term",
      id: 1,
      school_year: "2027-2028",
      semester: "2nd",
      starts_at: null,
      ends_at: null,
      enrollment_opens_at: null,
      enrollment_closes_at: null,
      add_drop_deadline_at: null,
      grading_deadline_at: null,
      status: "semester_ongoing",
      status_label: "Semester Ongoing",
    },
  ],
}

function assignment(id: number, code: string, professorId: number | null) {
  return {
    section_id: id,
    section_code: "A",
    subject_id: id * 10,
    subject_code: code,
    subject_title: code,
    units: 3,
    professor_id: professorId,
    professor_name: null,
    recommended_professor_id: null,
    rationale: [],
    override_reason: null,
    schedule_days: "MON",
    starts_at_time: "08:00:00",
    ends_at_time: "10:00:00",
    room: "R1",
    modality: "f2f",
  }
}

const report = {
  data: {
    academic_term_id: 1,
    college: "ccs",
    threshold_units: null,
    limits: [
      { employment_type: "full_time", label: "Full-time", max_units: 6 },
      { employment_type: "part_time", label: "Part-time", max_units: null },
    ],
    required_teaching_units: 9,
    required_assignments: 3,
    equivalent_faculty_loads: null,
    assigned_count: 2,
    unassigned_count: 1,
    overloaded_count: 1,
    faculty: [
      {
        professor_id: 12,
        professor_name: "Prof. Reyes",
        employment_type: "full_time",
        employment_type_label: "Full-time",
        total_units: 9,
        max_units: 6,
        limit_source: "employment_type",
        override: null,
        overloaded: true,
        assignments: [assignment(1, "IT101", 12), assignment(2, "IT102", 12)],
      },
      {
        professor_id: 13,
        professor_name: "Prof. Santos",
        employment_type: "part_time",
        employment_type_label: "Part-time",
        total_units: 3,
        max_units: 6,
        limit_source: "override",
        override: { max_units: 6, reason: "Only one who can teach it." },
        overloaded: false,
        assignments: [assignment(3, "IT103", 13)],
      },
    ],
    unassigned: [assignment(4, "IT104", null)],
    idle_faculty: [
      {
        professor_id: 14,
        professor_name: "Prof. Cruz",
        employment_type: "part_time",
        employment_type_label: "Part-time",
        max_units: null,
        limit_source: null,
        override: null,
      },
    ],
  },
}

function requestUrl(input: RequestInfo | URL): string {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

const dean = {
  userId: "dean-1",
  displayName: "Dean",
  role: "dean" as const,
  college: "ccs" as const,
  signedInAt: "2026-09-26T00:00:00Z",
}

describe("DeanFacultyLoadWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  const bodyOf = (init: RequestInit | undefined) =>
    typeof init?.body === "string" ? init.body : ""
  const calls = (method: string, part: string) =>
    (
      fetchMock.mock.calls as [RequestInfo | URL, RequestInit | undefined][]
    ).filter(
      ([input, init]) =>
        requestUrl(input).includes(part) && init?.method === method,
    )

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    fetchMock.mockImplementation((input, init) => {
      const url = requestUrl(input)
      if (url.includes("/sections/") && init?.method === "PUT") {
        return Promise.resolve(
          new Response(JSON.stringify({ data: {} }), { status: 500 }),
        )
      }
      if (
        url.includes("/faculty-load-overrides/") &&
        init?.method === "DELETE"
      ) {
        return Promise.resolve(new Response(null, { status: 204 }))
      }
      if (url.includes("/faculty-load-overrides/") && init?.method === "PUT") {
        return Promise.resolve(
          new Response(JSON.stringify({ data: { max_units: 12 } })),
        )
      }
      const body = url.endsWith("/academic-terms")
        ? terms
        : url.endsWith("/faculty-load-report")
          ? report
          : { data: [] }
      return Promise.resolve(new Response(JSON.stringify(body)))
    })
  })
  afterEach(() => vi.unstubAllGlobals())

  it("lists every professor, including those with no classes, against their maximum", async () => {
    renderWithSession(<DeanFacultyLoadWorkspace />, { session: dean })

    const reyes = (await screen.findByText("Prof. Reyes")).closest<HTMLElement>(
      "li",
    )!
    expect(within(reyes).getByText("9 of 6 units")).toBeInTheDocument()
    expect(within(reyes).getByText("Over limit")).toBeInTheDocument()
    expect(within(reyes).getByText("Full-time limit")).toBeInTheDocument()

    expect(screen.getByText("Prof. Santos")).toBeInTheDocument()
    expect(screen.getByText("Own max load")).toBeInTheDocument()
    expect(screen.getByText("Only one who can teach it.")).toBeInTheDocument()

    // Prof. Cruz has no section and no limit anywhere.
    expect(screen.getByText("Prof. Cruz")).toBeInTheDocument()
    expect(screen.getByText("0 units")).toBeInTheDocument()
  })

  it("opens a professor's subject loads by clicking their name", async () => {
    const user = userEvent.setup()
    renderWithSession(<DeanFacultyLoadWorkspace />, { session: dean })

    await user.click(await screen.findByRole("button", { name: "Prof. Reyes" }))

    const dialog = await screen.findByRole("dialog")
    expect(within(dialog).getByText("Prof. Reyes")).toBeInTheDocument()
    expect(within(dialog).getByText(/2 sections/)).toBeInTheDocument()
    expect(within(dialog).getByText(/IT101/)).toBeInTheDocument()
    expect(within(dialog).getByText(/IT102/)).toBeInTheDocument()
  })

  it("filters by load status", async () => {
    const user = userEvent.setup()
    renderWithSession(<DeanFacultyLoadWorkspace />, { session: dean })

    await screen.findByText("Prof. Reyes")
    await user.click(screen.getByRole("button", { name: "Over limit (1)" }))

    expect(screen.getByText("Prof. Reyes")).toBeInTheDocument()
    expect(screen.queryByText("Prof. Santos")).not.toBeInTheDocument()
    expect(screen.queryByText("Prof. Cruz")).not.toBeInTheDocument()
  })

  it("sets a professor's own max load with a reason", async () => {
    const user = userEvent.setup()
    renderWithSession(<DeanFacultyLoadWorkspace />, { session: dean })

    const reyes = (await screen.findByText("Prof. Reyes")).closest<HTMLElement>(
      "li",
    )!
    await user.click(
      within(reyes).getByRole("button", { name: "Set max load" }),
    )
    const dialog = await screen.findByRole("dialog", {
      name: /Set max load for Prof\. Reyes/,
    })
    const units = within(dialog).getByLabelText("Maximum units")
    await user.clear(units)
    await user.type(units, "12")
    await user.type(
      within(dialog).getByLabelText("Reason"),
      "Nobody else is available",
    )
    await user.click(
      within(dialog).getByRole("button", { name: "Save max load" }),
    )

    await waitFor(() =>
      expect(calls("PUT", "/faculty-load-overrides/12")).toHaveLength(1),
    )
    expect(
      JSON.parse(bodyOf(calls("PUT", "/faculty-load-overrides/12")[0][1])),
    ).toEqual({
      max_units: 12,
      reason: "Nobody else is available",
    })
  })

  it("removes a professor's own max load", async () => {
    const user = userEvent.setup()
    renderWithSession(<DeanFacultyLoadWorkspace />, { session: dean })

    await user.click(
      await screen.findByRole("button", { name: "Remove own max load" }),
    )

    await waitFor(() =>
      expect(calls("DELETE", "/faculty-load-overrides/13")).toHaveLength(1),
    )
  })

  it("assigns a professor to a section that has none", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) => {
      const url = requestUrl(input)
      if (url.includes("/sections/4/professor") && init?.method === "PUT") {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              error: {
                code: "VALIDATION_FAILED",
                message: "The submitted data is invalid.",
                errors: {
                  professor_id: [
                    "This professor is already assigned to IT101 on MON.",
                  ],
                },
                request_id: "r1",
              },
            }),
            { status: 422 },
          ),
        )
      }
      const body = url.endsWith("/academic-terms")
        ? terms
        : url.endsWith("/faculty-load-report")
          ? report
          : { data: [] }
      return Promise.resolve(new Response(JSON.stringify(body)))
    })
    renderWithSession(<DeanFacultyLoadWorkspace />, { session: dean })

    await user.click(
      await screen.findByRole("button", { name: "Assign professor" }),
    )
    const dialog = await screen.findByRole("dialog", {
      name: /Professor for IT104 · Section A/,
    })
    await user.click(within(dialog).getByLabelText("Professor"))
    await user.click(await screen.findByRole("option", { name: /Prof\. Cruz/ }))
    await user.click(
      within(dialog).getByRole("button", { name: "Save professor" }),
    )

    await waitFor(() =>
      expect(calls("PUT", "/sections/4/professor")).toHaveLength(1),
    )
    expect(
      JSON.parse(bodyOf(calls("PUT", "/sections/4/professor")[0][1])),
    ).toEqual({
      professor_id: 14,
    })
    // The server's reason for refusing is shown, and the dialog stays open.
    expect(
      await screen.findByText(/already assigned to IT101 on MON/),
    ).toBeInTheDocument()
  })

  it("is only for the Dean and makes no request for another role", () => {
    renderWithSession(<DeanFacultyLoadWorkspace />, {
      session: { ...dean, role: "program_chair" },
    })

    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
    expect(calls("GET", "/faculty-load-report")).toHaveLength(0)
  })
})
