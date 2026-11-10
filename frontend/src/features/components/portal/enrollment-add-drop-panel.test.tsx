import { screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { EnrollmentAddDropPanel } from "@/features/components/portal/enrollment-add-drop-panel"
import type { Enrollment } from "@/features/schemas/enrollment-schema"
import { renderWithSession } from "@/tests/render-app"

function url(input: RequestInfo | URL) {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

const paginationLinks = {
  first: "https://api.test/x?page=1",
  last: "https://api.test/x?page=1",
  prev: null,
  next: null,
}
const paginationMeta = {
  current_page: 1,
  last_page: 1,
  per_page: 20,
  total: 1,
}

const enrolledEnrollment: Enrollment = {
  type: "enrollment",
  id: 9,
  student_id: 4,
  student_number: "2026-0001",
  student_name: null,
  student_year_level: null,
  student_financial_status: null,
  student_financial_status_label: null,
  academic_term_id: 2,
  status: "enrolled",
  status_label: "Enrolled",
  total_units: 3,
  requires_overload_approval: false,
  program_head_comment: null,
  submitted_at: "2026-07-30T00:00:00Z",
  program_head_decided_at: null,
  registrar_decided_at: "2026-07-30T00:00:00Z",
  payment_confirmed_at: "2026-07-30T00:00:00Z",
  enrolled_at: "2026-07-30T00:00:00Z",
  subjects: [
    {
      section_id: 5,
      subject_id: 50,
      subject_code: "CS101",
      paired_subject_id: null,
      subject_title: "Programming 1",
      status: "selected",
      status_label: "Selected",
    },
  ],
  revisions: [],
  queue_ticket: {
    ticket_number: "Q000009",
    queue_date: "2026-07-30",
    status: "waiting",
    status_label: "Waiting",
    priority: "regular",
    priority_label: "Regular",
    position: 0,
  },
  assessment: null,
}

const heldSection = {
  type: "section",
  id: 5,
  academic_term_id: 2,
  subject_id: 7,
  section_code: "A",
  professor_id: null,
  schedule_days: "MWF",
  starts_at_time: "08:00:00",
  ends_at_time: "09:00:00",
  room: null,
  capacity: 40,
  capacity_source: "manual",
  viability_threshold: null,
  enrolled_count: 1,
  remaining_seats: 39,
  is_block_exclusive: false,
  status: "published",
  status_label: "Published",
} as const

const addableSection = {
  ...heldSection,
  id: 6,
  subject_id: 8,
  section_code: "B",
  enrolled_count: 0,
  remaining_seats: 40,
}

const section150 = { ...addableSection, id: 21, subject_id: 13, section_code: "D" }
const section210 = { ...addableSection, id: 31, subject_id: 15, section_code: "E" }
const section050 = { ...addableSection, id: 41, subject_id: 14, section_code: "F" }

const subjects = [
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
  {
    type: "subject",
    id: 8,
    code: "CS102",
    title: "Data Structures",
    units: 3,
    status: "active",
    status_label: "Active",
    is_completion_only: false,
  },
  {
    type: "subject",
    id: 900,
    code: "OTHER900",
    title: "Another Program's Subject",
    units: 3,
    status: "active",
    status_label: "Active",
    is_completion_only: false,
  },
] as const

function prospectusEntry(
  subjectId: number,
  code: string,
  title: string,
  mark: string | null = null,
) {
  return {
    subject_id: subjectId,
    code,
    title,
    units: 3,
    is_required: true,
    offered_either_semester: false,
    is_completion_only: false,
    mark,
    mark_label: mark === null ? null : "Very Good",
    final_grade: null,
    status: mark === null ? null : "locked",
    status_label: mark === null ? null : "Locked",
    academic_term_id: mark === null ? null : 1,
    term_label: mark === null ? null : "2025-2026 · 1st",
    attempt_count: mark === null ? 0 : 1,
    prerequisites: [],
  }
}

/**
 * What `GET /prospectus` returns for this student: their own curriculum only. The global
 * `/subjects` catalog also holds OTHER900, which is not in it (Doc 12: other curricula's subjects
 * must never reach the Add subject picker). CS101 is on the enrollment already, CS050 was passed,
 * CS301 has no open section, CS210 needs a prerequisite the student has not met yet.
 */
function prospectusResponse() {
  return Promise.resolve(
    new Response(
      JSON.stringify({
        data: {
          type: "prospectus",
          student_id: 4,
          student_number: "2026-0001",
          program_code: "BSIT",
          program_name: "BS Information Technology",
          curriculum_id: 1,
          curriculum_name: "BSIT 2023 Curriculum",
          effective_school_year: "2023-2024",
          year_level: 1,
          enrollment_category: "regular",
          enrollment_category_label: "Regular",
          enrollment_category_derived_at: null,
          semesters: [
            {
              year_level: 1,
              semester: "1st",
              semester_label: "1st Semester",
              entries: [
                prospectusEntry(14, "CS050", "Intro to Computing", "1.50"),
                prospectusEntry(7, "CS101", "Programming 1"),
                prospectusEntry(8, "CS102", "Data Structures"),
                prospectusEntry(13, "CS150", "Discrete Mathematics"),
              ],
            },
            {
              year_level: 2,
              semester: "1st",
              semester_label: "1st Semester",
              entries: [
                prospectusEntry(12, "CS301", "Operating Systems"),
                prospectusEntry(15, "CS210", "Algorithms"),
              ],
            },
          ],
          unplaced_entries: [],
          curriculum_transition: null,
          transferee_credits: [],
        },
      }),
    ),
  )
}

/**
 * What `GET /eligible-subjects` returns: only subjects from the student's OWN
 * curriculum. CS102 is addable (one open section); `OTHER900` exists in the
 * global `/subjects` catalog below but is deliberately absent here, which is
 * exactly the stakeholder complaint (Doc 12): other curricula's subjects must
 * never appear in the Add subject picker.
 */
const eligibleSubjects = [
  {
    type: "eligible_subject",
    subject_id: 8,
    code: "CS102",
    title: "Data Structures",
    units: 3,
    paired_subject_id: null,
    year_level: 1,
    semester: "1st",
    is_required: true,
    is_eligible: true,
    reasons: [],
    preference_score: null,
    preference_reasons: [],
    available_sections: [
      {
        ...addableSection,
        college: null,
        is_own_department: true,
        subject_code: "CS102",
        subject_title: "Data Structures",
      },
    ],
  },
  {
    type: "eligible_subject",
    subject_id: 13,
    code: "CS150",
    title: "Discrete Mathematics",
    units: 3,
    paired_subject_id: null,
    year_level: 1,
    semester: "1st",
    is_required: true,
    is_eligible: true,
    reasons: [],
    preference_score: null,
    preference_reasons: [],
    available_sections: [
      {
        ...addableSection,
        id: 21,
        subject_id: 13,
        section_code: "D",
        college: null,
        is_own_department: true,
        subject_code: "CS150",
        subject_title: "Discrete Mathematics",
      },
    ],
  },
  {
    type: "eligible_subject",
    subject_id: 12,
    code: "CS301",
    title: "Operating Systems",
    units: 3,
    paired_subject_id: null,
    year_level: 3,
    semester: "1st",
    is_required: true,
    is_eligible: false,
    reasons: [
      { code: "no_sections_available", message: "No open section this term." },
    ],
    preference_score: null,
    preference_reasons: [],
    available_sections: [],
  },
] as const

function eligibleSubjectsResponse() {
  return Promise.resolve(
    new Response(JSON.stringify({ data: eligibleSubjects })),
  )
}

const changeRequest = {
  type: "enrollment_change_request",
  id: 3,
  enrollment_id: 9,
  student_number: "2026-0001",
  request_type: "drop",
  request_type_label: "Drop subject",
  subject_code: "CS101",
  from_section_code: "A",
  to_section_code: null,
  reason: "Overloaded this term.",
  status: "pending",
  status_label: "Pending",
  decided_at: null,
  decision_reason: null,
  created_at: "2026-08-04T00:00:00Z",
} as const

const studentSession = {
  userId: "4",
  displayName: "Student",
  role: "student",
  signedInAt: "2026-07-29T12:00:00Z",
} as const

function mockRoutes(overrides: { onPost?: () => unknown } = {}) {
  return (input: RequestInfo | URL, init?: RequestInit) => {
    const target = url(input)
    if (target.includes("/change-requests") && init?.method === "POST")
      return Promise.resolve(
        new Response(
          JSON.stringify(overrides.onPost?.() ?? { data: changeRequest }),
          {
            status: 201,
          },
        ),
      )
    if (target.includes("/enrollment-change-requests"))
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: [changeRequest],
            links: paginationLinks,
            meta: paginationMeta,
          }),
        ),
      )
    if (target.includes("/prospectus")) return prospectusResponse()
    if (target.includes("/eligible-subjects")) return eligibleSubjectsResponse()
    if (target.includes("/subjects"))
      return Promise.resolve(new Response(JSON.stringify({ data: subjects })))
    if (target.includes("/sections"))
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: [
              heldSection,
              addableSection,
              section150,
              section210,
              section050,
            ],
          }),
        ),
      )
    return Promise.resolve(new Response(JSON.stringify({ data: [] })))
  }
}

describe("EnrollmentAddDropPanel", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("shows the closed-window message instead of the form when the window is not open", () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: [] })))
    renderWithSession(
      <EnrollmentAddDropPanel
        enrollment={enrolledEnrollment}
        windowOpen={false}
        windowMessage="The add/drop window opens once enrollment closes for this term."
        windowClosesAt={null}
      />,
      { session: studentSession },
    )

    expect(
      screen.getByText(
        "The add/drop window opens once enrollment closes for this term.",
      ),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("table", { name: "Your subjects" }),
    ).not.toBeInTheDocument()
  })

  it("lists current subjects and submits a drop request with a reason when the window is open", async () => {
    const user = userEvent.setup()
    let postBody: unknown = null
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (target.includes("/change-requests") && init?.method === "POST") {
        postBody = init.body ? JSON.parse(init.body as string) : null
        return Promise.resolve(
          new Response(JSON.stringify({ data: changeRequest }), {
            status: 201,
          }),
        )
      }
      if (target.includes("/enrollment-change-requests"))
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [],
              links: paginationLinks,
              meta: paginationMeta,
            }),
          ),
        )
      if (target.includes("/eligible-subjects"))
        return eligibleSubjectsResponse()
      if (target.includes("/subjects"))
        return Promise.resolve(new Response(JSON.stringify({ data: subjects })))
      if (target.includes("/sections"))
        return Promise.resolve(
          new Response(
          JSON.stringify({
            data: [
              heldSection,
              addableSection,
              section150,
              section210,
              section050,
            ],
          }),
        ),
        )
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })

    renderWithSession(
      <EnrollmentAddDropPanel
        enrollment={enrolledEnrollment}
        windowOpen={true}
        windowMessage="The add/drop window is open."
        windowClosesAt="2026-08-20T00:00:00Z"
      />,
      { session: studentSession },
    )

    const table = await screen.findByRole("table", { name: "Your subjects" })
    expect(within(table).getByText("Programming 1")).toBeInTheDocument()

    expect(
      await within(table).findByRole("button", { name: "Change subject" }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("heading", { name: "Add subject" }),
    ).toBeInTheDocument()
    await user.click(
      within(table).getByRole("button", { name: "Drop subject" }),
    )
    const dialog = await screen.findByRole("alertdialog")
    await user.type(
      within(dialog).getByLabelText("Reason"),
      "Overloaded this term.",
    )
    await user.click(
      within(dialog).getByRole("button", { name: "Submit request" }),
    )

    await vi.waitFor(() =>
      expect(postBody).toEqual({
        type: "drop",
        from_section_id: 5,
        reason: "Overloaded this term.",
      }),
    )
  })

  it("submits a change subject request with a selected section and reason", async () => {
    const user = userEvent.setup()
    let postBody: unknown = null
    const alternateSection = {
      ...heldSection,
      id: 99,
      section_code: "C",
      enrolled_count: 5,
      remaining_seats: 35,
    }
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (target.includes("/change-requests") && init?.method === "POST") {
        postBody = init.body ? JSON.parse(init.body as string) : null
        return Promise.resolve(
          new Response(JSON.stringify({ data: changeRequest }), {
            status: 201,
          }),
        )
      }
      if (target.includes("/enrollment-change-requests"))
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [],
              links: paginationLinks,
              meta: paginationMeta,
            }),
          ),
        )
      if (target.includes("/eligible-subjects"))
        return eligibleSubjectsResponse()
      if (target.includes("/subjects"))
        return Promise.resolve(new Response(JSON.stringify({ data: subjects })))
      if (target.includes("/sections"))
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [heldSection, alternateSection, addableSection],
            }),
          ),
        )
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })

    renderWithSession(
      <EnrollmentAddDropPanel
        enrollment={enrolledEnrollment}
        windowOpen={true}
        windowMessage="The add/drop window is open."
        windowClosesAt="2026-08-20T00:00:00Z"
      />,
      { session: studentSession },
    )

    const table = await screen.findByRole("table", { name: "Your subjects" })
    const changeButton = await within(table).findByRole("button", {
      name: "Change subject",
    })
    await user.click(changeButton)
    const dialog = await screen.findByRole("alertdialog")
    expect(
      within(dialog).getByText(/Change subject for Programming 1\?/),
    ).toBeInTheDocument()

    // Select the alternate section
    const sectionTrigger = within(dialog).getByRole("combobox", {
      name: "New section",
    })
    await user.click(sectionTrigger)
    const option = await screen.findByRole("option", {
      name: /Section C/,
    })
    await user.click(option)

    await user.type(
      within(dialog).getByLabelText("Reason"),
      "Schedule conflict with work.",
    )
    await user.click(
      within(dialog).getByRole("button", { name: "Submit request" }),
    )

    await vi.waitFor(() =>
      expect(postBody).toEqual({
        type: "change_section",
        from_section_id: 5,
        to_section_id: 99,
        reason: "Schedule conflict with work.",
      }),
    )
  })

  it("offers the subjects of the student's own prospectus in a searchable picker and submits the add request", async () => {
    const user = userEvent.setup()
    let postBody: unknown = null
    fetchMock.mockImplementation((input, init) => {
      if (url(input).includes("/change-requests") && init?.method === "POST") {
        postBody = init.body ? JSON.parse(init.body as string) : null
        return Promise.resolve(
          new Response(JSON.stringify({ data: changeRequest }), {
            status: 201,
          }),
        )
      }
      return mockRoutes()(input, init)
    })
    renderWithSession(
      <EnrollmentAddDropPanel
        enrollment={enrolledEnrollment}
        windowOpen={true}
        windowMessage="The add/drop window is open."
        windowClosesAt={null}
      />,
      { session: studentSession },
    )

    await screen.findByRole("table", { name: "Your subjects" })
    const subjectPicker = screen.getByRole("combobox", { name: "Subject" })

    // Prospectus scoping: the global catalog also holds OTHER900, CS301 has no open section and
    // CS050 was already passed; none of them may be offered.
    await user.click(subjectPicker)
    expect(
      await screen.findByRole("option", { name: /CS102/ }),
    ).toBeInTheDocument()
    expect(screen.getByRole("option", { name: /CS150/ })).toBeInTheDocument()
    // CS210 needs a prerequisite the student has not met, but it is in their prospectus and has a
    // section, and the server accepts it; the old eligible pool used to hide it.
    expect(screen.getByRole("option", { name: /CS210/ })).toBeInTheDocument()
    expect(
      screen.queryByRole("option", { name: /OTHER900/ }),
    ).not.toBeInTheDocument()
    expect(
      screen.queryByRole("option", { name: /CS301/ }),
    ).not.toBeInTheDocument()
    expect(
      screen.queryByRole("option", { name: /CS050/ }),
    ).not.toBeInTheDocument()
    // CS101 is already on the enrollment.
    expect(
      screen.queryByRole("option", { name: /CS101/ }),
    ).not.toBeInTheDocument()
    // The missing one is explained.
    expect(
      screen.getByText(
        "1 more subject from your prospectus has no open section this term.",
      ),
    ).toBeInTheDocument()

    // Typing filters the list.
    await user.type(subjectPicker, "data")
    expect(
      await screen.findByRole("option", { name: /CS102/ }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("option", { name: /CS150/ }),
    ).not.toBeInTheDocument()
    await user.click(screen.getByRole("option", { name: /CS102/ }))

    await user.click(screen.getByRole("combobox", { name: "Section" }))
    await user.click(await screen.findByRole("option", { name: /Section B/ }))
    await user.type(
      screen.getByLabelText("Reason"),
      "Needed for my prospectus.",
    )
    await user.click(screen.getByRole("button", { name: "Submit request" }))

    await vi.waitFor(() =>
      expect(postBody).toEqual({
        type: "add",
        to_section_id: 6,
        reason: "Needed for my prospectus.",
      }),
    )
  })

  it("says so when nothing in the prospectus matches the search", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockRoutes())
    renderWithSession(
      <EnrollmentAddDropPanel
        enrollment={enrolledEnrollment}
        windowOpen={true}
        windowMessage="The add/drop window is open."
        windowClosesAt={null}
      />,
      { session: studentSession },
    )

    await screen.findByRole("table", { name: "Your subjects" })
    const subjectPicker = screen.getByRole("combobox", { name: "Subject" })
    // Wait for the curriculum list to arrive before searching it.
    await user.click(subjectPicker)
    await screen.findByRole("option", { name: /CS102/ })

    await user.type(subjectPicker, "zzzz")

    expect(
      await screen.findByText(
        "No subject in your prospectus matches that search.",
      ),
    ).toBeInTheDocument()
  })

  it("says why the picker is empty when none of the remaining subjects has an open section", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) =>
      url(input).includes("/sections")
        ? Promise.resolve(
            new Response(JSON.stringify({ data: [heldSection, section050] })),
          )
        : mockRoutes()(input, init),
    )
    renderWithSession(
      <EnrollmentAddDropPanel
        enrollment={enrolledEnrollment}
        windowOpen={true}
        windowMessage="The add/drop window is open."
        windowClosesAt={null}
      />,
      { session: studentSession },
    )

    await screen.findByRole("table", { name: "Your subjects" })
    await user.click(screen.getByRole("combobox", { name: "Subject" }))

    expect(
      await screen.findByText(
        "None of your remaining prospectus subjects has an open section this term.",
      ),
    ).toBeInTheDocument()
    expect(
      screen.getByText(
        "4 more subjects from your prospectus have no open section this term.",
      ),
    ).toBeInTheDocument()
  })

  it("only offers sections of the term the enrollment belongs to", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) =>
      url(input).includes("/sections")
        ? Promise.resolve(
            new Response(
              JSON.stringify({
                data: [
                  heldSection,
                  { ...addableSection, academic_term_id: 99 },
                ],
              }),
            ),
          )
        : mockRoutes()(input, init),
    )
    renderWithSession(
      <EnrollmentAddDropPanel
        enrollment={enrolledEnrollment}
        windowOpen={true}
        windowMessage="The add/drop window is open."
        windowClosesAt={null}
      />,
      { session: studentSession },
    )

    await screen.findByRole("table", { name: "Your subjects" })
    await user.click(screen.getByRole("combobox", { name: "Subject" }))

    // CS102's only section is in another term, so it cannot be added to this enrollment.
    expect(
      await screen.findByText(
        "None of your remaining prospectus subjects has an open section this term.",
      ),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("option", { name: /CS102/ }),
    ).not.toBeInTheDocument()
  })

  it("searches the prospectus subjects inside the Change subject dialog too", async () => {
    const user = userEvent.setup()
    let postBody: unknown = null
    fetchMock.mockImplementation((input, init) => {
      if (url(input).includes("/change-requests") && init?.method === "POST") {
        postBody = init.body ? JSON.parse(init.body as string) : null
        return Promise.resolve(
          new Response(JSON.stringify({ data: changeRequest }), {
            status: 201,
          }),
        )
      }
      return mockRoutes()(input, init)
    })
    renderWithSession(
      <EnrollmentAddDropPanel
        enrollment={enrolledEnrollment}
        windowOpen={true}
        windowMessage="The add/drop window is open."
        windowClosesAt={null}
      />,
      { session: studentSession },
    )

    const table = await screen.findByRole("table", { name: "Your subjects" })
    await user.click(
      await within(table).findByRole("button", { name: "Change subject" }),
    )
    const dialog = await screen.findByRole("alertdialog")
    const newSubject = within(dialog).getByRole("combobox", {
      name: "New subject",
    })

    // The box starts filled with the held subject; clearing it must really
    // clear it (no snapping back to the held subject's label) so the student
    // can search.
    expect(newSubject).toHaveValue(
      "CS101 — Programming 1 (Same subject / change section)",
    )
    await user.clear(newSubject)
    expect(newSubject).toHaveValue("")
    await user.type(newSubject, "data")
    // The option list is portalled INTO the alert dialog, otherwise the
    // dialog's modal layer swallows the click.
    const option = await screen.findByRole("option", { name: /CS102/ })
    expect(dialog).toContainElement(option)
    expect(
      screen.queryByRole("option", { name: /OTHER900/ }),
    ).not.toBeInTheDocument()
    await user.click(option)

    await user.click(
      within(dialog).getByRole("combobox", { name: "New section" }),
    )
    await user.click(await screen.findByRole("option", { name: /Section B/ }))
    await user.type(within(dialog).getByLabelText("Reason"), "Better fit.")
    await user.click(
      within(dialog).getByRole("button", { name: "Submit request" }),
    )

    await vi.waitFor(() =>
      expect(postBody).toEqual({
        type: "change_section",
        from_section_id: 5,
        to_section_id: 6,
        reason: "Better fit.",
      }),
    )
  })

  it("shows the deadline reminder when the window is open", () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: [] })))
    renderWithSession(
      <EnrollmentAddDropPanel
        enrollment={enrolledEnrollment}
        windowOpen={true}
        windowMessage="The add/drop window is open."
        windowClosesAt="2026-08-20T00:00:00Z"
      />,
      { session: studentSession },
    )

    expect(screen.getByText(/The add\/drop window closes/)).toBeInTheDocument()
  })

  it("shows the student's own request history", async () => {
    fetchMock.mockImplementation(mockRoutes())
    renderWithSession(
      <EnrollmentAddDropPanel
        enrollment={enrolledEnrollment}
        windowOpen={true}
        windowMessage="The add/drop window is open."
        windowClosesAt={null}
      />,
      { session: studentSession },
    )

    const table = await screen.findByRole("table", {
      name: "Your add/drop requests",
    })
    expect(within(table).getByText("Drop subject")).toBeInTheDocument()
  })

  it("has no detectable accessibility violations once loaded", async () => {
    fetchMock.mockImplementation(mockRoutes())
    const { container } = renderWithSession(
      <EnrollmentAddDropPanel
        enrollment={enrolledEnrollment}
        windowOpen={true}
        windowMessage="The add/drop window is open."
        windowClosesAt={null}
      />,
      { session: studentSession },
    )

    await screen.findByRole("table", { name: "Your subjects" })
    expect(await axe(container)).toHaveNoViolations()
  })
})
