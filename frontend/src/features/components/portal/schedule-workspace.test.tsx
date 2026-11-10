import { fireEvent, screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { ScheduleWorkspace } from "@/features/components/portal/schedule-workspace"
import { renderWithSession } from "@/tests/render-app"

const archivedTerm = {
  type: "academic-term",
  id: 2,
  school_year: "2024-2025",
  semester: "1st",
  starts_at: null,
  ends_at: null,
  enrollment_opens_at: null,
  enrollment_closes_at: null,
  add_drop_deadline_at: null,
  grading_deadline_at: null,
  status: "archived",
  status_label: "Archived",
} as const

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
    archivedTerm,
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
      id: 301,
      code: "OLD101",
      title: "Retired Elective",
      units: 3,
      status: "active",
      status_label: "Active",
      is_completion_only: false,
    },
  ],
} as const

const sections = {
  data: [
    {
      type: "section",
      id: 11,
      academic_term_id: 1,
      section_plan_id: 70,
      subject_id: 101,
      section_code: "IT101",
      professor_id: 12,
      schedule_days: "MW",
      starts_at_time: "08:00:00",
      ends_at_time: "10:00:00",
      room: "LAB 1",
      modality: "f2f",
      capacity: 40,
      capacity_source: "plan",
      viability_threshold: null,
      enrolled_count: 0,
      remaining_seats: 40,
      is_block_exclusive: true,
      status: "planned",
      status_label: "Planned",
    },
    {
      type: "section",
      id: 12,
      academic_term_id: 1,
      section_plan_id: 71,
      subject_id: 201,
      section_code: "IT201",
      professor_id: 13,
      schedule_days: "TTh",
      starts_at_time: "10:00:00",
      ends_at_time: "12:00:00",
      room: "LAB 2",
      modality: "hyflex_a",
      capacity: 40,
      capacity_source: "plan",
      viability_threshold: null,
      enrolled_count: 0,
      remaining_seats: 40,
      is_block_exclusive: true,
      status: "planned",
      status_label: "Planned",
    },
    {
      type: "section",
      id: 13,
      academic_term_id: 2,
      section_plan_id: 72,
      subject_id: 301,
      section_code: "OLD101",
      professor_id: 12,
      schedule_days: "F",
      starts_at_time: "13:00:00",
      ends_at_time: "15:00:00",
      room: "LAB 3",
      modality: "f2f",
      capacity: 40,
      capacity_source: "plan",
      viability_threshold: null,
      enrolled_count: 0,
      remaining_seats: 40,
      is_block_exclusive: true,
      status: "planned",
      status_label: "Planned",
    },
  ],
} as const

const plans = {
  data: [
    {
      type: "academic-term-section-plan",
      id: 70,
      academic_term_id: 1,
      curriculum_id: 10,
      college: "ccs",
      year_level: 1,
      section_count: 1,
      students_per_block: 40,
      status: "draft",
      status_label: "Draft",
      submitted_at: null,
    },
    {
      type: "academic-term-section-plan",
      id: 71,
      academic_term_id: 1,
      curriculum_id: 10,
      college: "ccs",
      year_level: 2,
      section_count: 1,
      students_per_block: 40,
      status: "submitted",
      status_label: "Submitted",
      submitted_at: "2026-08-01T00:00:00Z",
    },
    {
      type: "academic-term-section-plan",
      id: 72,
      academic_term_id: 2,
      curriculum_id: 10,
      college: "ccs",
      year_level: 1,
      section_count: 1,
      students_per_block: 40,
      status: "draft",
      status_label: "Draft",
      submitted_at: null,
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

const rooms = {
  data: [
    { type: "room_option", id: 1, name: "LAB 1" },
    { type: "room_option", id: 2, name: "3A" },
  ],
} as const

const roomOccupancy = {
  data: [
    {
      type: "room_occupancy",
      section_id: 11,
      section_code: "IT101",
      subject_code: "IT101",
      subject_title: "Introduction to Computing",
      professor_name: "Prof. Reyes",
      schedule_days: "MON/WED",
      starts_at_time: "08:00:00",
      ends_at_time: "10:00:00",
      modality: "f2f",
      college: "ccs",
      is_own_college: true,
      is_lecture_component: false,
    },
    {
      type: "room_occupancy",
      section_id: 99,
      section_code: "ELEM101",
      subject_code: "ELEM101",
      subject_title: "Foundations",
      professor_name: "Prof. Cruz",
      schedule_days: "TUE",
      starts_at_time: "10:00:00",
      ends_at_time: "12:00:00",
      modality: "f2f",
      college: "coe",
      is_own_college: false,
      is_lecture_component: false,
    },
  ],
} as const

function requestUrl(input: RequestInfo | URL): string {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

function renderWorkspace() {
  return renderWithSession(<ScheduleWorkspace />, {
    session: {
      userId: "chair-1",
      displayName: "Program Chair",
      role: "program_chair",
      college: "ccs",
      signedInAt: "2026-08-09T00:00:00Z",
    },
  })
}

describe("ScheduleWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  let sectionSaveError: unknown = null
  let patchedBody: unknown = null
  let section11Published = false
  let changeRequestBody: Record<string, unknown> | null = null

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    sectionSaveError = null
    patchedBody = null
    section11Published = false
    changeRequestBody = null
    fetchMock.mockImplementation((input, init) => {
      const url = requestUrl(input)
      if (url.endsWith("/sections/11") && init?.method === "PATCH") {
        if (sectionSaveError !== null)
          return Promise.resolve(
            new Response(JSON.stringify(sectionSaveError), { status: 422 }),
          )
        patchedBody = typeof init.body === "string" ? JSON.parse(init.body) : null
        // The request uses `override_reason`; the response resource (like the
        // real API) echoes it back as `manual_override_reason` — a plain
        // spread would leak the request-only key into the `.strict()`
        // response schema and fail contract validation.
        const { override_reason, ...requestFields } = (patchedBody ?? {}) as Record<string, unknown>
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                ...sections.data[0],
                ...requestFields,
                ...(override_reason !== undefined ? { manual_override_reason: override_reason } : {}),
              },
            }),
          ),
        )
      }
      if (url.endsWith("/sections/11/change-requests") && init?.method === "POST") {
        changeRequestBody =
          typeof init.body === "string"
            ? (JSON.parse(init.body) as Record<string, unknown>)
            : null
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                type: "section_change_request",
                id: 5,
                section_id: 11,
                section_code: "IT101",
                subject_code: "IT101",
                subject_title: "Introduction to Computing",
                academic_term_id: 1,
                status: "pending",
                status_label: "Pending",
                reason: "Room repair",
                requested_by_name: "Program Chair",
                changes: [{ field: "room", label: "Room", old: "LAB 1", new: "LAB 9" }],
                decided_by_name: null,
                decided_at: null,
                decision_reason: null,
                created_at: "2026-09-26T00:00:00Z",
              },
            }),
            { status: 201 },
          ),
        )
      }
      if (url.endsWith("/room-options")) return Promise.resolve(new Response(JSON.stringify(rooms)))
      if (url.includes("/room-occupancy")) return Promise.resolve(new Response(JSON.stringify(roomOccupancy)))
      const body = url.endsWith("/academic-terms")
        ? terms
        : url.endsWith("/sections")
          ? section11Published
            ? {
                data: [
                  { ...sections.data[0], status: "published", status_label: "Published" },
                  ...sections.data.slice(1),
                ],
              }
            : sections
          : url.endsWith("/subjects")
            ? subjects
            : url.includes("/faculty-members")
              ? faculty
              : url.includes("/academic-term-section-plans")
                ? plans
                : { data: [] }
      return Promise.resolve(new Response(JSON.stringify(body)))
    })
  })

  afterEach(() => vi.unstubAllGlobals())

  it("shows the IT101 block only under its linked year tab, not the long flat list", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    expect(
      await screen.findByRole("article", { name: "IT101 section" }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("article", { name: "IT201 section" }),
    ).not.toBeInTheDocument()

    await user.click(screen.getByRole("tab", { name: "2nd Year" }))

    expect(
      await screen.findByRole("article", { name: "IT201 section" }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("article", { name: "IT101 section" }),
    ).not.toBeInTheDocument()
  })

  it("keeps a section editable while its plan is submitted and pending Dean/Executive Director review", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    await user.click(await screen.findByRole("tab", { name: "2nd Year" }))
    const sectionCard = await screen.findByRole("article", { name: "IT201 section" })
    await user.click(within(sectionCard).getByRole("button", { name: /View schedule & assign/ }))

    const editButton = await screen.findByRole("button", { name: "Edit" })
    expect(editButton).toBeEnabled()

    await user.click(editButton)
    expect(
      screen.getByRole("dialog", { name: /Edit section assignment/ }),
    ).toBeInTheDocument()
  })

  it("shows the room conflict returned when saving a section assignment", async () => {
    sectionSaveError = {
      error: {
        code: "VALIDATION_FAILED",
        message: "The submitted data is invalid.",
        errors: {
          room: [
            "This room is already physically occupied by another section at the proposed time.",
          ],
        },
        request_id: "test-room-conflict",
      },
    }
    const user = userEvent.setup()
    renderWorkspace()

    const sectionCard = await screen.findByRole("article", { name: "IT101 section" })
    await user.click(within(sectionCard).getByRole("button", { name: /View schedule & assign/ }))

    await user.click(await screen.findByRole("button", { name: "Edit" }))
    const dialog = screen.getByRole("dialog", {
      name: "Edit section assignment",
    })
    await user.click(
      within(dialog).getByRole("button", { name: "Save changes" }),
    )

    expect(
      await screen.findByText(
        "This room is already physically occupied by another section at the proposed time.",
      ),
    ).toBeInTheDocument()
    expect(
      screen.queryByText("The submitted data is invalid."),
    ).not.toBeInTheDocument()
  })

  it("turns a published section's Edit into Request change and sends the change to the Registrar Head", async () => {
    section11Published = true
    const user = userEvent.setup()
    renderWorkspace()

    const sectionCard = await screen.findByRole("article", { name: "IT101 section" })
    await user.click(within(sectionCard).getByRole("button", { name: /View schedule & assign/ }))

    await user.click(await screen.findByRole("button", { name: "Request change" }))
    const dialog = await screen.findByRole("dialog", {
      name: "Request a schedule change",
    })
    expect(
      within(dialog).getByText(/can only change with the Registrar Head/),
    ).toBeInTheDocument()

    const send = within(dialog).getByRole("button", { name: "Send change request" })
    expect(send).toBeDisabled()

    const room = within(dialog).getByLabelText("Room")
    // Not `user.clear`: an empty room swaps the fields for the room picker.
    fireEvent.change(room, { target: { value: "LAB 9" } })
    expect(send).toBeDisabled()
    await user.type(within(dialog).getByLabelText("Reason for the change"), "Room repair")
    expect(send).toBeEnabled()
    await user.click(send)

    await waitFor(() => expect(changeRequestBody).not.toBeNull())
    expect(changeRequestBody).toEqual({
      reason: "Room repair",
      changes: { room: "LAB 9" },
    })
    // No direct schedule edit was attempted.
    expect(patchedBody).toBeNull()
  })

  it("lets the Program Chair switch to an archived term and view its schedule read-only", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    expect(await screen.findByRole("article", { name: "IT101 section" })).toBeInTheDocument()
    expect(
      screen.getByText("Viewing the current term. Assignments are editable."),
    ).toBeInTheDocument()

    await user.selectOptions(
      screen.getByLabelText("Academic term"),
      String(archivedTerm.id),
    )

    expect(
      await screen.findByText(/Viewing an archived schedule/),
    ).toBeInTheDocument()
    const archivedCard = await screen.findByRole("article", { name: "OLD101 section" })
    await user.click(within(archivedCard).getByRole("button", { name: /View schedule & assign/ }))
    expect(screen.getByRole("button", { name: "Archived" })).toBeDisabled()
  })

  it("lets the Program Chair pick a room and an open calendar slot, then save with an override reason", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    const sectionCard = await screen.findByRole("article", { name: "IT101 section" })
    await user.click(within(sectionCard).getByRole("button", { name: /View schedule & assign/ }))

    await user.click(await screen.findByRole("button", { name: "Edit" }))
    const dialog = screen.getByRole("dialog", { name: "Edit section assignment" })

    await user.click(within(dialog).getByRole("button", { name: /room & schedule/ }))
    const picker = await screen.findByRole("dialog", { name: "Pick a room" })
    await user.click(within(picker).getByRole("button", { name: "LAB 1" }))

    const calendarStep = await screen.findByRole("dialog", { name: "LAB 1 — pick an open slot" })
    // Section 11's own current booking (MON/WED 8-10) is excluded from its
    // own occupancy view, so 7:30 AM Monday reads as available.
    await user.click(
      within(calendarStep).getByRole("button", {
        name: "Monday 7:30 AM — available, assign a class",
      }),
    )

    const formStep = await screen.findByRole("dialog", { name: "Confirm the schedule in LAB 1" })
    expect(within(formStep).getByRole("button", { name: "Mon" })).toHaveAttribute(
      "aria-pressed",
      "true",
    )
    expect(within(formStep).getByLabelText("Start time")).toHaveValue("07:30")
    expect(within(formStep).getByLabelText("End time")).toHaveValue("09:00")

    await user.click(within(formStep).getByRole("button", { name: "Save schedule" }))

    // Back in the main dialog, the picked schedule is already filled in.
    expect(within(dialog).getByLabelText("Schedule days")).toHaveValue("M")
    expect(within(dialog).getByLabelText("Room")).toHaveValue("LAB 1")
    expect(within(dialog).getByLabelText("Start time")).toHaveValue("07:30")
    expect(within(dialog).getByLabelText("End time")).toHaveValue("09:00")

    await user.type(within(dialog).getByLabelText("Override reason"), "Moved off a conflicting slot")
    await user.click(within(dialog).getByRole("button", { name: "Save changes" }))

    await waitFor(() => {
      expect(patchedBody).toMatchObject({
        room: "LAB 1",
        schedule_days: "M",
        starts_at_time: "07:30:00",
        ends_at_time: "09:00:00",
        modality: "f2f",
        override_reason: "Moved off a conflicting slot",
      })
    })
  })

  it("caps the picker's meeting days at two, disabling a third", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    const sectionCard = await screen.findByRole("article", { name: "IT101 section" })
    await user.click(within(sectionCard).getByRole("button", { name: /View schedule & assign/ }))

    await user.click(await screen.findByRole("button", { name: "Edit" }))
    const dialog = screen.getByRole("dialog", { name: "Edit section assignment" })
    await user.click(within(dialog).getByRole("button", { name: /room & schedule/ }))
    const picker = await screen.findByRole("dialog", { name: "Pick a room" })
    await user.click(within(picker).getByRole("button", { name: "LAB 1" }))

    const calendarStep = await screen.findByRole("dialog", { name: "LAB 1 — pick an open slot" })
    await user.click(
      within(calendarStep).getByRole("button", {
        name: "Monday 7:30 AM — available, assign a class",
      }),
    )

    const formStep = await screen.findByRole("dialog", { name: "Confirm the schedule in LAB 1" })
    await user.click(within(formStep).getByRole("button", { name: "Wed" }))

    expect(within(formStep).getByRole("button", { name: "Mon" })).toHaveAttribute(
      "aria-pressed",
      "true",
    )
    expect(within(formStep).getByRole("button", { name: "Wed" })).toHaveAttribute(
      "aria-pressed",
      "true",
    )
    expect(within(formStep).getByRole("button", { name: "Fri" })).toBeDisabled()
  })
})
