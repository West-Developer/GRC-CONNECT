import { screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { FacultyAssignmentWorkspace } from "@/features/components/portal/faculty-assignment-workspace"
import { renderWithSession } from "@/tests/render-app"

const terms = {
  data: [
    {
      type: "academic-term",
      id: 2,
      school_year: "2026-2027",
      semester: "1st",
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
const subjects = {
  data: [
    {
      type: "subject",
      id: 7,
      code: "CS101",
      title: "Programming 1",
      units: 3,
      status: "active",
      status_label: "Active",
      is_completion_only: false,
    },
  ],
}
const unassigned = {
  data: [
    {
      type: "section",
      id: 5,
      academic_term_id: 2,
      subject_id: 7,
      section_code: "A",
      professor_id: null,
      schedule_days: "MWF",
      starts_at_time: "08:00:00",
      ends_at_time: "09:00:00",
      room: "R101",
      capacity: 30,
      capacity_source: "plan",
      viability_threshold: 25,
      enrolled_count: 0,
      remaining_seats: 30,
      is_block_exclusive: null,
      status: "planned",
      status_label: "Planned",
    },
  ],
}
const directory = {
  data: [
    {
      type: "faculty_member",
      id: 12,
      name: "Prof. Reyes",
      college: "ccs",
      status: "active",
      status_label: "Active",
      employment_type: "part_time",
      employment_type_label: "Part-time",
      planning_unit_reference: null,
      is_assignable: true,
    },
  ],
}
const availability = {
  data: [
    {
      type: "faculty_availability",
      id: 1,
      professor_id: 12,
      day_of_week: 1,
      starts_at_time: "08:00:00",
      ends_at_time: "12:00:00",
    },
  ],
}
const preferences = {
  data: [
    {
      type: "faculty_subject_preference",
      id: 2,
      professor_id: 12,
      academic_term_id: 2,
      subject_id: 7,
      rank: 1,
    },
  ],
}
function url(input: RequestInfo | URL) {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : input.url
}

/** Opens a `Select` trigger and picks an item. */
async function selectOption(
  user: ReturnType<typeof userEvent.setup>,
  labelText: string,
  optionName: string,
) {
  await user.click(screen.getByLabelText(labelText))
  await user.click(await screen.findByRole("option", { name: optionName }))
}

describe("FacultyAssignmentWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("uses a faculty member's term-independent availability for the selected term", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) =>
      Promise.resolve(
        new Response(
          JSON.stringify(
            url(input).endsWith("/academic-terms")
              ? terms
              : url(input).endsWith("/subjects")
                ? subjects
                : url(input).endsWith("/faculty-members")
                  ? directory
                  : url(input).endsWith("/faculty-availabilities")
                    ? availability
                    : url(input).endsWith("/faculty-subject-preferences")
                      ? preferences
                      : init?.method === "PATCH"
                        ? { data: unassigned.data[0] }
                        : unassigned,
          ),
          { status: 200 },
        ),
      ),
    )
    renderWithSession(<FacultyAssignmentWorkspace />, {
      session: {
        userId: "4",
        displayName: "Chair",
        role: "program_chair",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })
    await screen.findByLabelText("Unassigned section")
    await selectOption(user, "Unassigned section", "CS101 · A")
    await selectOption(user, "Faculty member", "Prof. Reyes")
    expect(screen.getByText(/Monday/)).toBeInTheDocument()
    expect(screen.getByText(/Preference #1/)).toBeInTheDocument()
    expect(screen.queryByText("private@example.test")).not.toBeInTheDocument()
    await user.click(
      screen.getByRole("button", { name: "Save faculty assignment" }),
    )
    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining("/sections/5"),
      expect.objectContaining({ method: "PATCH" }),
    )
  })

  it("shows the server conflict when the assignment double-books faculty", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) =>
      Promise.resolve(
        init?.method === "PATCH"
          ? new Response(
              JSON.stringify({
                error: {
                  code: "VALIDATION_FAILED",
                  message: "Conflict",
                  errors: {
                    schedule_days: [
                      "The assigned professor has a schedule conflict.",
                    ],
                  },
                  request_id: "req-7",
                },
              }),
              { status: 422 },
            )
          : new Response(
              JSON.stringify(
                url(input).endsWith("/academic-terms")
                  ? terms
                  : url(input).endsWith("/subjects")
                    ? subjects
                    : url(input).endsWith("/faculty-members")
                      ? directory
                      : url(input).endsWith("/faculty-availabilities")
                        ? availability
                        : url(input).endsWith("/faculty-subject-preferences")
                          ? preferences
                          : unassigned,
              ),
            ),
      ),
    )
    renderWithSession(<FacultyAssignmentWorkspace />, {
      session: {
        userId: "4",
        displayName: "Chair",
        role: "program_chair",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })
    await screen.findByLabelText("Unassigned section")
    await selectOption(user, "Unassigned section", "CS101 · A")
    await selectOption(user, "Faculty member", "Prof. Reyes")
    await user.click(
      screen.getByRole("button", { name: "Save faculty assignment" }),
    )
    expect(
      await screen.findByText(
        "The assigned professor has a schedule conflict.",
      ),
    ).toBeInTheDocument()
  })

  it("retries failed reference data in place", async () => {
    const user = userEvent.setup()
    let directoryAttempts = 0
    fetchMock.mockImplementation((input) => {
      if (url(input).endsWith("/faculty-members")) {
        directoryAttempts += 1
        return Promise.resolve(
          directoryAttempts <= 2
            ? new Response(
                JSON.stringify({
                  error: {
                    code: "UNAVAILABLE",
                    message: "Unavailable",
                    errors: {},
                    request_id: "req-retry",
                  },
                }),
                { status: 500 },
              )
            : new Response(JSON.stringify(directory)),
        )
      }
      return Promise.resolve(
        new Response(
          JSON.stringify(
            url(input).endsWith("/academic-terms")
              ? terms
              : url(input).endsWith("/subjects")
                ? subjects
                : url(input).endsWith("/faculty-availabilities")
                  ? availability
                  : url(input).endsWith("/faculty-subject-preferences")
                    ? preferences
                    : unassigned,
          ),
        ),
      )
    })
    renderWithSession(<FacultyAssignmentWorkspace />, {
      session: {
        userId: "4",
        displayName: "Chair",
        role: "program_chair",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })
    await screen.findByText(/Unavailable/, {}, { timeout: 3_000 })
    await user.click(screen.getByRole("button", { name: "Try again" }))
    await screen.findByLabelText("Unassigned section")
    await selectOption(user, "Unassigned section", "CS101 · A")
    await selectOption(user, "Faculty member", "Prof. Reyes")
    expect(directoryAttempts).toBe(3)
  })

  it("has no detectable accessibility violations once loaded", async () => {
    fetchMock.mockImplementation((input, init) =>
      Promise.resolve(
        new Response(
          JSON.stringify(
            url(input).endsWith("/academic-terms")
              ? terms
              : url(input).endsWith("/subjects")
                ? subjects
                : url(input).endsWith("/faculty-members")
                  ? directory
                  : url(input).endsWith("/faculty-availabilities")
                    ? availability
                    : url(input).endsWith("/faculty-subject-preferences")
                      ? preferences
                      : init?.method === "PATCH"
                        ? { data: unassigned.data[0] }
                        : unassigned,
          ),
          { status: 200 },
        ),
      ),
    )
    const { container } = renderWithSession(<FacultyAssignmentWorkspace />, {
      session: {
        userId: "4",
        displayName: "Chair",
        role: "program_chair",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })

    await screen.findByLabelText("Unassigned section")
    expect(await axe(container)).toHaveNoViolations()
  })
})
