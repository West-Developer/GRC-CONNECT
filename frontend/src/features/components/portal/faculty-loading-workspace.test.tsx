import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { FacultyLoadingWorkspace } from "@/features/components/portal/faculty-loading-workspace"
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
} as const

const subjects = {
  data: [
    {
      type: "subject",
      id: 101,
      code: "IT101",
      title: "Introduction to Computing",
      units: 3,
      status: "active",
      status_label: "Active",
      is_completion_only: false,
    },
    {
      type: "subject",
      id: 201,
      code: "IT201",
      title: "Data Structures",
      units: 3,
      status: "active",
      status_label: "Active",
      is_completion_only: false,
    },
    {
      type: "subject",
      id: 999,
      code: "LEAD4",
      title: "Leadership 4 (Other College)",
      units: 3,
      status: "active",
      status_label: "Active",
      is_completion_only: false,
    },
  ],
} as const

const faculty = {
  data: [
    {
      type: "faculty_member",
      id: 12,
      name: "Prof. Reyes",
      college: "ccs",
      status: "active",
      status_label: "Active",
      employment_type: "full_time",
      employment_type_label: "Full-time",
      planning_unit_reference: 33,
      is_assignable: true,
    },
    {
      type: "faculty_member",
      id: 13,
      name: "Prof. Santos",
      college: "ccs",
      status: "active",
      status_label: "Active",
      employment_type: "part_time",
      employment_type_label: "Part-time",
      planning_unit_reference: null,
      is_assignable: true,
    },
  ],
} as const

const facultyIncludingInactive = {
  data: [
    ...faculty.data,
    {
      type: "faculty_member",
      id: 14,
      name: "Marian S. Villanueva",
      college: "ccs",
      status: "disabled",
      status_label: "Inactive",
      employment_type: "part_time",
      employment_type_label: "Part-time",
      planning_unit_reference: null,
      is_assignable: false,
    },
  ],
} as const

const facultyLoadReport = {
  data: {
    academic_term_id: 1,
    college: "ccs",
    threshold_units: 18,
    limits: [
      { employment_type: "full_time", label: "Full-time", max_units: 24 },
      { employment_type: "part_time", label: "Part-time", max_units: null },
    ],
    required_teaching_units: 6,
    required_assignments: 2,
    equivalent_faculty_loads: 1,
    assigned_count: 2,
    unassigned_count: 0,
    overloaded_count: 0,
    faculty: [
      {
        professor_id: 12,
        professor_name: "Prof. Reyes",
        employment_type: "full_time",
        employment_type_label: "Full-time",
        total_units: 3,
        max_units: 24,
        limit_source: "employment_type",
        override: null,
        overloaded: false,
        assignments: [
          {
            section_id: 11,
            section_code: "IT101",
            subject_id: 101,
            subject_code: "IT101",
            subject_title: "Introduction to Computing",
            units: 3,
            professor_id: 12,
            professor_name: "Prof. Reyes",
            recommended_professor_id: 12,
            rationale: ["Ranked preference"],
            override_reason: null,
            schedule_days: "MW",
            starts_at_time: "08:00:00",
            ends_at_time: "10:00:00",
            room: "LAB 1",
            modality: "f2f",
          },
        ],
      },
      {
        professor_id: 13,
        professor_name: "Prof. Santos",
        employment_type: "part_time",
        employment_type_label: "Part-time",
        total_units: 3,
        max_units: 6,
        limit_source: "override",
        override: {
          max_units: 6,
          reason: "No one else can teach Data Structures.",
        },
        overloaded: false,
        assignments: [
          {
            section_id: 12,
            section_code: "IT201",
            subject_id: 201,
            subject_code: "IT201",
            subject_title: "Data Structures",
            units: 3,
            professor_id: 13,
            professor_name: "Prof. Santos",
            recommended_professor_id: 13,
            rationale: ["Ranked preference"],
            override_reason: null,
            schedule_days: "TTh",
            starts_at_time: "10:00:00",
            ends_at_time: "12:00:00",
            room: "LAB 2",
            modality: "hyflex_a",
          },
        ],
      },
    ],
    unassigned: [],
  },
} as const

function requestUrl(input: RequestInfo | URL): string {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

function renderWorkspace() {
  return renderWithSession(<FacultyLoadingWorkspace />, {
    session: {
      userId: "chair-1",
      displayName: "Program Chair",
      role: "program_chair",
      college: "ccs",
      signedInAt: "2026-08-09T00:00:00Z",
    },
  })
}

describe("FacultyLoadingWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    fetchMock.mockImplementation((input, init) => {
      const url = requestUrl(input)
      const body = url.endsWith("/academic-terms")
        ? terms
        : url.endsWith("/subjects")
          ? subjects
          : url.includes("/faculty-members")
            ? url.includes("include_inactive=1")
              ? facultyIncludingInactive
              : faculty
            : url.endsWith("/faculty-load-report")
              ? facultyLoadReport
              : url.includes("/faculty-load-threshold") &&
                  init?.method === "PUT"
                ? { data: { max_units: 18 } }
                : url.includes("/faculty-load-limits/") &&
                    init?.method === "PUT"
                  ? { data: { max_units: 24 } }
                  : url.includes("/faculty-load-overrides/") &&
                      init?.method === "PUT"
                    ? { data: { max_units: 30 } }
                    : { data: [] }
      if (
        url.includes("/faculty-load-overrides/") &&
        init?.method === "DELETE"
      ) {
        return Promise.resolve(new Response(null, { status: 204 }))
      }
      return Promise.resolve(new Response(JSON.stringify(body)))
    })
  })

  afterEach(() => vi.unstubAllGlobals())

  it("narrows the Faculty Load Report to a professor selected from the searchable dropdown", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    await user.click(await screen.findByLabelText("Professor"))
    await user.click(await screen.findByRole("option", { name: "Prof. Reyes" }))

    expect(
      await screen.findByText("Assigned subjects: IT101"),
    ).toBeInTheDocument()
    expect(
      screen.queryByText("Assigned subjects: IT201"),
    ).not.toBeInTheDocument()
  })

  it("narrows the Faculty Load Report to a subject selected from the searchable dropdown", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    await user.click(await screen.findByLabelText("Subject"))
    await user.click(
      await screen.findByRole("option", { name: "IT201 — Data Structures" }),
    )

    expect(
      await screen.findByText("Assigned subjects: IT201"),
    ).toBeInTheDocument()
    expect(
      screen.queryByText("Assigned subjects: IT101"),
    ).not.toBeInTheDocument()
  })

  it("only offers subjects that appear in this college's faculty load report", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    await user.click(await screen.findByLabelText("Subject"))

    expect(
      await screen.findByRole("option", {
        name: "IT101 — Introduction to Computing",
      }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("option", { name: "IT201 — Data Structures" }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("option", { name: /Other College/ }),
    ).not.toBeInTheDocument()
  })

  const bodyOf = (init: RequestInit | undefined) =>
    typeof init?.body === "string" ? init.body : ""

  function calls(method: string, part: string) {
    return (
      fetchMock.mock.calls as [RequestInfo | URL, RequestInit | undefined][]
    ).filter(
      ([input, init]) =>
        requestUrl(input).includes(part) && init?.method === method,
    )
  }

  it("shows each professor's load against the maximum that applies and where it comes from", async () => {
    renderWorkspace()

    expect(await screen.findByText("3 of 24 units")).toBeInTheDocument()
    expect(screen.getByText("Full-time limit")).toBeInTheDocument()
    expect(screen.getByText("3 of 6 units")).toBeInTheDocument()
    expect(screen.getByText("Own max load")).toBeInTheDocument()
    expect(
      screen.getByText("No one else can teach Data Structures."),
    ).toBeInTheDocument()
  })

  it("saves the full-time maximum on its own", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    const input = await screen.findByLabelText("Full-time maximum units")
    expect(input).toHaveValue(24)
    await user.clear(input)
    await user.type(input, "21")
    await user.click(
      screen.getByRole("button", { name: "Save full-time maximum units" }),
    )

    await waitFor(() =>
      expect(calls("PUT", "/faculty-load-limits/full_time")).toHaveLength(1),
    )
    const [, init] = calls("PUT", "/faculty-load-limits/full_time")[0]
    expect(JSON.parse(bodyOf(init))).toEqual({ max_units: 21 })
    // The other limits were not touched.
    expect(calls("PUT", "/faculty-load-limits/part_time")).toHaveLength(0)
    expect(calls("PUT", "/faculty-load-threshold")).toHaveLength(0)
  })

  it("leaves the part-time maximum unset until someone sets one", async () => {
    renderWorkspace()

    const input = await screen.findByLabelText("Part-time maximum units")
    expect(input).toHaveValue(null)
    expect(input).toHaveAttribute("placeholder", "Not set")
    expect(
      screen.getByRole("button", { name: "Save part-time maximum units" }),
    ).toBeDisabled()
  })

  it("saves the college default through the older threshold endpoint", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    const input = await screen.findByLabelText("Everyone else (default)")
    await user.clear(input)
    await user.type(input, "15")
    await user.click(
      screen.getByRole("button", { name: "Save default maximum units" }),
    )

    await waitFor(() =>
      expect(calls("PUT", "/faculty-load-threshold")).toHaveLength(1),
    )
  })

  it("sets one professor's own max load, and needs a reason", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    const reyes = (await screen.findByText("Prof. Reyes")).closest<HTMLElement>(
      "div.rounded-lg",
    )!
    await user.click(
      within(reyes).getByRole("button", { name: "Set max load" }),
    )

    const dialog = await screen.findByRole("dialog", {
      name: /Set max load for Prof\. Reyes/,
    })
    const save = within(dialog).getByRole("button", { name: "Save max load" })
    expect(save).toBeDisabled()

    const units = within(dialog).getByLabelText("Maximum units")
    await user.clear(units)
    await user.type(units, "30")
    expect(save).toBeDisabled()
    await user.type(
      within(dialog).getByLabelText("Reason"),
      "Only professor for Networking",
    )
    await user.click(save)

    await waitFor(() =>
      expect(calls("PUT", "/faculty-load-overrides/12")).toHaveLength(1),
    )
    const [, init] = calls("PUT", "/faculty-load-overrides/12")[0]
    expect(JSON.parse(bodyOf(init))).toEqual({
      max_units: 30,
      reason: "Only professor for Networking",
    })
  })

  it("removes a professor's own max load", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    await user.click(
      await screen.findByRole("button", { name: "Remove own max load" }),
    )

    await waitFor(() =>
      expect(calls("DELETE", "/faculty-load-overrides/13")).toHaveLength(1),
    )
  })
})
