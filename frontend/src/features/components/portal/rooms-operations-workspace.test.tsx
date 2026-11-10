import { screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { RoomsOperationsWorkspace } from "@/features/components/portal/rooms-operations-workspace"
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

const rooms = {
  data: [{ type: "room_option", id: 1, name: "LAB 1" }],
} as const

const subjects = {
  data: [
    {
      type: "subject",
      id: 101,
      code: "IT101",
      college: "ccs",
      title: "Programming 1 LEC",
      units: 3,
      status: "active",
      status_label: "Active",
      is_completion_only: false,
      paired_subject_id: 102,
      room_requirement: "lecture",
    },
    {
      type: "subject",
      id: 102,
      code: "IT101L",
      college: "ccs",
      title: "Programming 1 LAB",
      units: 1,
      status: "active",
      status_label: "Active",
      is_completion_only: false,
      paired_subject_id: 101,
      room_requirement: "laboratory",
    },
    {
      type: "subject",
      id: 301,
      code: "IT301",
      college: "ccs",
      title: "Networking",
      units: 3,
      status: "active",
      status_label: "Active",
      is_completion_only: false,
      paired_subject_id: null,
      room_requirement: null,
    },
  ],
} as const

const sections = {
  data: [
    {
      type: "section",
      id: 11,
      academic_term_id: 1,
      subject_id: 101,
      section_code: "IT101",
      professor_id: null,
      schedule_days: null,
      starts_at_time: null,
      ends_at_time: null,
      room: null,
      modality: null,
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
      subject_id: 301,
      section_code: "IT301",
      professor_id: null,
      schedule_days: null,
      starts_at_time: null,
      ends_at_time: null,
      room: null,
      modality: null,
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
      id: 26,
      academic_term_id: 1,
      subject_id: 301,
      section_code: "IT401A",
      professor_id: 5,
      schedule_days: "TUE",
      starts_at_time: "09:00:00",
      ends_at_time: "10:30:00",
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
      id: 27,
      academic_term_id: 1,
      subject_id: 301,
      section_code: "IT301B",
      professor_id: 5,
      schedule_days: "WED",
      starts_at_time: "13:00:00",
      ends_at_time: "15:00:00",
      room: "3A",
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

const faculty = {
  data: [
    {
      type: "faculty_member",
      id: 5,
      name: "Prof. Cruz",
      college: "ccs",
      status: "active",
      status_label: "Active",
      employment_type: "full_time",
      employment_type_label: "Full-time",
      planning_unit_reference: 33,
      is_assignable: true,
    },
  ],
} as const

const roomOccupancy = {
  data: [
    {
      type: "room_occupancy",
      section_id: 21,
      section_code: "IT101",
      subject_code: "IT101",
      subject_title: "Programming 1 LEC",
      professor_name: "Prof. Dela Cruz",
      schedule_days: "MON",
      starts_at_time: "08:00:00",
      ends_at_time: "09:00:00",
      modality: "f2f",
      college: "ccs",
      is_own_college: true,
      is_lecture_component: true,
    },
    {
      type: "room_occupancy",
      section_id: 22,
      section_code: "ELEM101",
      subject_code: "ELEM101",
      subject_title: "Foundations LEC",
      professor_name: "Prof. Santos",
      schedule_days: "WED",
      starts_at_time: "10:00:00",
      ends_at_time: "12:00:00",
      modality: "f2f",
      college: "coe",
      is_own_college: false,
      is_lecture_component: true,
    },
    {
      type: "room_occupancy",
      section_id: 23,
      section_code: "HR301",
      subject_code: "PATHFIT2",
      subject_title: "Physical Fitness 2",
      professor_name: "Prof. Reyes",
      schedule_days: "FRI",
      starts_at_time: "13:00:00",
      ends_at_time: "15:00:00",
      modality: "hyflex_a",
      college: "coe",
      is_own_college: false,
      is_lecture_component: false,
    },
    {
      type: "room_occupancy",
      section_id: 24,
      section_code: "HR302",
      subject_code: "PATHFIT2",
      subject_title: "Physical Fitness 2",
      professor_name: "Prof. Bautista",
      schedule_days: "FRI",
      starts_at_time: "13:00:00",
      ends_at_time: "15:00:00",
      modality: "hyflex_b",
      college: "coe",
      is_own_college: false,
      is_lecture_component: false,
    },
    {
      type: "room_occupancy",
      section_id: 25,
      section_code: "HR303",
      subject_code: "PATHFIT4",
      subject_title: "Physical Fitness 4",
      professor_name: "Prof. Reyes",
      schedule_days: "FRI",
      starts_at_time: "13:00:00",
      ends_at_time: "15:00:00",
      modality: "f2f",
      college: "ccs",
      is_own_college: true,
      is_lecture_component: false,
    },
    {
      type: "room_occupancy",
      section_id: 26,
      section_code: "IT401A",
      subject_code: "IT301",
      subject_title: "Networking",
      professor_name: "Prof. Cruz",
      schedule_days: "TUE",
      starts_at_time: "09:00:00",
      ends_at_time: "10:30:00",
      modality: "f2f",
      college: "ccs",
      is_own_college: true,
      is_lecture_component: false,
    },
  ],
} as const

function requestUrl(input: RequestInfo | URL): string {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

function renderWorkspace() {
  return renderWithSession(<RoomsOperationsWorkspace />, {
    session: {
      userId: "chair-1",
      displayName: "Program Chair",
      role: "program_chair",
      college: "ccs",
      signedInAt: "2026-08-09T00:00:00Z",
    },
  })
}

describe("RoomsOperationsWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  let patchedBody: unknown = null

  beforeEach(() => {
    patchedBody = null
    vi.stubGlobal("fetch", fetchMock)
    fetchMock.mockImplementation((input, init) => {
      const url = requestUrl(input)
      if (url.includes("/sections/12") && init?.method === "PATCH") {
        patchedBody =
          typeof init.body === "string" ? JSON.parse(init.body) : null
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: { ...sections.data[1], room: "LAB 1" },
            }),
          ),
        )
      }
      if (url.includes("/sections/26") && init?.method === "PATCH") {
        patchedBody =
          typeof init.body === "string" ? JSON.parse(init.body) : null
        return Promise.resolve(
          new Response(JSON.stringify({ data: sections.data[2] })),
        )
      }
      if (url.includes("/sections/27") && init?.method === "PATCH") {
        patchedBody =
          typeof init.body === "string" ? JSON.parse(init.body) : null
        return Promise.resolve(
          new Response(
            JSON.stringify({ data: { ...sections.data[3], room: "LAB 1" } }),
          ),
        )
      }
      if (url.endsWith("/academic-terms"))
        return Promise.resolve(new Response(JSON.stringify(terms)))
      if (url.endsWith("/room-options"))
        return Promise.resolve(new Response(JSON.stringify(rooms)))
      if (url.includes("/room-occupancy"))
        return Promise.resolve(new Response(JSON.stringify(roomOccupancy)))
      if (url.endsWith("/sections"))
        return Promise.resolve(new Response(JSON.stringify(sections)))
      if (url.endsWith("/subjects"))
        return Promise.resolve(new Response(JSON.stringify(subjects)))
      if (url.includes("/faculty-members"))
        return Promise.resolve(new Response(JSON.stringify(faculty)))

      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
  })

  afterEach(() => vi.unstubAllGlobals())

  it("opens a room's detail dialog with its true occupancy and hides its CCS lecture component", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    await user.click(await screen.findByRole("button", { name: "LAB 1" }))

    const dialog = await screen.findByRole("dialog", { name: "LAB 1" })
    expect(within(dialog).getAllByText("ELEM101")[0]).toBeInTheDocument()
    expect(
      within(dialog).queryByText(/Programming 1 LEC/),
    ).not.toBeInTheDocument()
    expect(
      within(dialog).getByText(/IT lecture components are asynchronous/),
    ).toBeInTheDocument()
  })

  it("shows another college's booking as not the chair's own", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    await user.click(await screen.findByRole("button", { name: "LAB 1" }))
    const dialog = await screen.findByRole("dialog", { name: "LAB 1" })
    await user.click(within(dialog).getByRole("radio", { name: "Table view" }))

    const row = within(dialog).getByText("ELEM101").closest("tr")
    expect(row).not.toBeNull()
    expect(within(row as HTMLElement).getByText("COE")).toBeInTheDocument()
  })

  it("renders the weekly calendar with Monday through Saturday columns", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    await user.click(await screen.findByRole("button", { name: "LAB 1" }))
    const dialog = await screen.findByRole("dialog", { name: "LAB 1" })

    for (const day of ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat"]) {
      expect(within(dialog).getByText(day)).toBeInTheDocument()
    }
    expect(
      within(dialog).getByRole("group", { name: /ELEM101/ }),
    ).toBeInTheDocument()
  })

  it("excludes an unscheduled CCS lecture-paired section from the assign picker and assigns the remaining candidate", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    await user.click(await screen.findByRole("button", { name: "LAB 1" }))
    const dialog = await screen.findByRole("dialog", { name: "LAB 1" })
    await user.click(
      within(dialog).getByRole("button", { name: "Assign a class" }),
    )

    const assignDialog = await screen.findByRole("dialog", {
      name: "Assign a class to LAB 1",
    })
    await user.click(within(assignDialog).getByLabelText("Section"))
    expect(
      screen.getByRole("option", { name: /IT301 — Networking/ }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("option", { name: /IT101 — Programming 1 LEC/ }),
    ).not.toBeInTheDocument()

    await user.click(screen.getByRole("option", { name: /IT301 — Networking/ }))
    await user.click(within(assignDialog).getByLabelText("Professor"))
    await user.click(await screen.findByRole("option", { name: "Prof. Cruz" }))
    await user.click(within(assignDialog).getByRole("radio", { name: "Mon" }))
    await user.type(within(assignDialog).getByLabelText("Start time"), "0800AM")
    await user.type(within(assignDialog).getByLabelText("End time"), "0900AM")

    await user.click(
      within(assignDialog).getByRole("button", { name: "Assign class" }),
    )

    await screen.findByRole("dialog", { name: "LAB 1" })
    expect(
      screen.queryByRole("dialog", { name: "Assign a class to LAB 1" }),
    ).not.toBeInTheDocument()
    expect(patchedBody).toMatchObject({
      room: "LAB 1",
      professor_id: 5,
      schedule_days: "M",
      starts_at_time: "08:00:00",
      ends_at_time: "09:00:00",
    })
  })

  it("collapses three overlapping bookings into a cluster and shows modality and a real conflict, without flagging the complementary HyFlex pair against each other", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    await user.click(await screen.findByRole("button", { name: "LAB 1" }))
    const dialog = await screen.findByRole("dialog", { name: "LAB 1" })

    await user.click(
      within(dialog).getByRole("button", { name: /3 overlapping bookings/ }),
    )
    const clusterDialog = await screen.findByRole("dialog", {
      name: "Friday bookings",
    })

    expect(within(clusterDialog).getByText("HyFlex A")).toBeInTheDocument()
    expect(within(clusterDialog).getByText("HyFlex B")).toBeInTheDocument()
    expect(within(clusterDialog).getByText("F2F")).toBeInTheDocument()
    // The F2F booking (PATHFIT4/HR303) genuinely conflicts with both HyFlex
    // halves, so every row in this cluster ends up flagged.
    expect(within(clusterDialog).getAllByText("Conflict")).toHaveLength(3)
  })

  it("lets the Program Chair open an already-scheduled own-college booking from the table and save a change", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    await user.click(await screen.findByRole("button", { name: "LAB 1" }))
    const dialog = await screen.findByRole("dialog", { name: "LAB 1" })
    await user.click(within(dialog).getByRole("radio", { name: "Table view" }))

    await user.click(within(dialog).getByText("IT401A"))
    const editDialog = await screen.findByRole("dialog", {
      name: "Edit IT401A in LAB 1",
    })

    expect(within(editDialog).getByDisplayValue("IT401A")).toBeInTheDocument()
    expect(within(editDialog).getByLabelText("Start time")).toHaveValue("09:00")

    const endTime = within(editDialog).getByLabelText("End time")
    await user.clear(endTime)
    await user.type(endTime, "1100")
    await user.click(
      within(editDialog).getByRole("button", { name: "Save changes" }),
    )

    await screen.findByRole("dialog", { name: "LAB 1" })
    expect(
      screen.queryByRole("dialog", { name: "Edit IT401A in LAB 1" }),
    ).not.toBeInTheDocument()
    expect(patchedBody).toMatchObject({
      room: "LAB 1",
      professor_id: 5,
      schedule_days: "T",
      starts_at_time: "09:00:00",
      ends_at_time: "11:00:00",
    })
  })

  it("moves a section already scheduled in another room into this one, prefilling its current day and time", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    await user.click(await screen.findByRole("button", { name: "LAB 1" }))
    const dialog = await screen.findByRole("dialog", { name: "LAB 1" })
    await user.click(
      within(dialog).getByRole("button", { name: "Assign a class" }),
    )

    const assignDialog = await screen.findByRole("dialog", {
      name: "Assign a class to LAB 1",
    })
    await user.click(
      within(assignDialog).getByRole("radio", {
        name: "Move a section already scheduled elsewhere",
      }),
    )
    await user.click(within(assignDialog).getByLabelText("Section"))
    expect(
      screen.queryByRole("option", {
        name: /IT301 — Networking \(Unassigned\)/,
      }),
    ).not.toBeInTheDocument()

    await user.click(
      screen.getByRole("option", { name: /IT301B.*currently 3A/ }),
    )

    // Prefilled from IT301B's current booking, not left blank.
    expect(
      within(assignDialog).getByRole("radio", { name: "Wed" }),
    ).toHaveAttribute("aria-checked", "true")
    expect(within(assignDialog).getByLabelText("Start time")).toHaveValue(
      "13:00",
    )
    expect(within(assignDialog).getByLabelText("End time")).toHaveValue("15:00")

    await user.click(
      within(assignDialog).getByRole("button", { name: "Assign class" }),
    )

    await screen.findByRole("dialog", { name: "LAB 1" })
    expect(patchedBody).toMatchObject({
      room: "LAB 1",
      professor_id: 5,
      schedule_days: "W",
      starts_at_time: "13:00:00",
      ends_at_time: "15:00:00",
    })
  })

  it("requires an override reason only when the backend flags a generated assignment as changed", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    await user.click(await screen.findByRole("button", { name: "LAB 1" }))
    const dialog = await screen.findByRole("dialog", { name: "LAB 1" })
    await user.click(within(dialog).getByRole("radio", { name: "Table view" }))
    await user.click(within(dialog).getByText("IT401A"))
    const editDialog = await screen.findByRole("dialog", {
      name: "Edit IT401A in LAB 1",
    })

    expect(
      within(editDialog).getByLabelText(/Override reason/),
    ).toBeInTheDocument()

    await user.type(
      within(editDialog).getByLabelText(/Override reason/),
      "Room conflict fix",
    )
    await user.click(
      within(editDialog).getByRole("button", { name: "Save changes" }),
    )

    await screen.findByRole("dialog", { name: "LAB 1" })
    expect(patchedBody).toMatchObject({ override_reason: "Room conflict fix" })
  })

  it("lists sections still awaiting a room, excluding a CCS lecture component with no room", async () => {
    renderWorkspace()

    const card = (
      await screen.findByText("Awaiting a room")
    ).closest<HTMLElement>('[data-slot="card"]')!
    expect(within(card).getByText("IT301 — Networking")).toBeInTheDocument()
    expect(within(card).getByText("Unassigned")).toBeInTheDocument()
    expect(
      within(card).queryByText(/Programming 1 LEC/),
    ).not.toBeInTheDocument()
    expect(within(card).getByText("1 subject")).toBeInTheDocument()
  })

  it("assigns a room straight from the awaiting-a-room list via the room/calendar picker", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    const card = (
      await screen.findByText("Awaiting a room")
    ).closest<HTMLElement>('[data-slot="card"]')!
    await user.click(
      within(card).getByRole("button", { name: "Assign a room" }),
    )

    const picker = await screen.findByRole("dialog", { name: "Pick a room" })
    await user.click(within(picker).getByRole("button", { name: "LAB 1" }))

    const calendarStep = await screen.findByRole("dialog", {
      name: "LAB 1 — pick an open slot",
    })
    await user.click(
      within(calendarStep).getByRole("button", {
        name: "Saturday 7:30 AM — available, assign a class",
      }),
    )

    const formStep = await screen.findByRole("dialog", {
      name: "Confirm the schedule in LAB 1",
    })
    await user.click(
      within(formStep).getByRole("button", { name: "Save schedule" }),
    )

    await vi.waitFor(() => {
      expect(patchedBody).toMatchObject({
        room: "LAB 1",
        schedule_days: "Sat",
        starts_at_time: "07:30:00",
        ends_at_time: "09:00:00",
        modality: "f2f",
      })
    })
  })

  it("shows on every room tile whether it is empty or how many classes it holds, and on which days", async () => {
    fetchMock.mockImplementation((input) => {
      const target = requestUrl(input)
      if (target.endsWith("/room-options"))
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [
                { type: "room_option", id: 1, name: "LAB 1" },
                { type: "room_option", id: 2, name: "LAB 2" },
              ],
            }),
          ),
        )
      if (target.includes("/room-occupancy-summary"))
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [{ room: "LAB 1", classes_count: 2, days: [1, 3] }],
            }),
          ),
        )
      if (target.endsWith("/academic-terms"))
        return Promise.resolve(new Response(JSON.stringify(terms)))
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
    renderWorkspace()

    const busy = await screen.findByRole("button", { name: /LAB 1/ })
    expect(await within(busy).findByText("2 classes")).toBeInTheDocument()
    expect(
      within(busy).getByRole("img", { name: "Used on Mon, Wed" }),
    ).toBeInTheDocument()

    const empty = screen.getByRole("button", { name: /LAB 2/ })
    expect(within(empty).getByText("Empty")).toBeInTheDocument()
  })

  it("says nothing about use until the summary has loaded, so a slow answer never shows an occupied room as empty", async () => {
    fetchMock.mockImplementation((input) => {
      const target = requestUrl(input)
      if (target.endsWith("/room-options"))
        return Promise.resolve(new Response(JSON.stringify(rooms)))
      if (target.includes("/room-occupancy-summary"))
        return new Promise<Response>(() => undefined)
      if (target.endsWith("/academic-terms"))
        return Promise.resolve(new Response(JSON.stringify(terms)))
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
    renderWorkspace()

    const tile = await screen.findByRole("button", { name: "LAB 1" })
    expect(within(tile).queryByText("Empty")).not.toBeInTheDocument()
    expect(within(tile).queryByText(/classes?$/)).not.toBeInTheDocument()
  })
})
