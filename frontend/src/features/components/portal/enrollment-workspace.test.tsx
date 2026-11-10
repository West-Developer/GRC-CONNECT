import { act, screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { EnrollmentWorkspace } from "@/features/components/portal/enrollment-workspace"
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

const eligibleSubject = {
  type: "eligible_subject",
  subject_id: 7,
  code: "CS101",
  title: "Programming 1",
  units: 3,
  paired_subject_id: null,
  year_level: 1,
  semester: "1st",
  is_required: true,
  is_eligible: true,
  reasons: [{ code: "eligible", message: "All requirements are met." }],
  preference_score: null,
  preference_reasons: [],
  available_sections: [
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
      viability_threshold: null,
      enrolled_count: 0,
      remaining_seats: 30,
      is_block_exclusive: null,
      status: "published",
      status_label: "Published",
      college: "ccs",
      is_own_department: true,
      subject_code: "CS101",
      subject_title: "Programming 1",
    },
  ],
}

const enrollmentBlock = {
  type: "enrollment_block",
  block_code: "IT201",
  year_level: 2,
  curriculum_id: 9,
  section_plan_id: 12,
  total_units: 6,
  seats_remaining: 7,
  capacity: 40,
  is_selectable: true,
  reasons: [],
  preference_score: null,
  preference_reasons: [],
  subjects: [
    {
      section_id: 5,
      subject_id: 7,
      code: "CS201",
      title: "Data Structures",
      units: 3,
      schedule_days: "MWF",
      starts_at_time: "08:00:00",
      ends_at_time: "09:00:00",
      room: "LAB-1",
      modality: "f2f",
      professor_name: "Dr. Cruz",
      capacity: 40,
      enrolled_count: 33,
      remaining_seats: 7,
    },
    {
      section_id: 6,
      subject_id: 8,
      code: "GE201",
      title: "Ethics",
      units: 3,
      schedule_days: "TTh",
      starts_at_time: "10:00:00",
      ends_at_time: "11:30:00",
      room: "R201",
      modality: "f2f",
      professor_name: "Dr. Reyes",
      capacity: 40,
      enrolled_count: 30,
      remaining_seats: 10,
    },
  ],
}

const regularViewer = {
  audience: "year_2",
  label: "2nd Year",
  opens_at: null,
  closes_at: null,
  is_open: true,
  reason: "open",
}

const addDropClosed = {
  is_open: false,
  reason: "enrollment_still_open",
  reason_message:
    "The add/drop window opens once enrollment closes for this term.",
  opens_at: null,
  closes_at: null,
}

const regularStudentSession = {
  userId: "1",
  displayName: "Student",
  role: "student",
  signedInAt: "2026-07-30T00:00:00Z",
} as const

const irregularViewer = {
  audience: "irregular",
  label: "Irregular Students",
  opens_at: null,
  closes_at: null,
  is_open: true,
  reason: "open",
}

const irregularStudentSession = {
  userId: "1",
  displayName: "Student",
  role: "student",
  signedInAt: "2026-07-30T00:00:00Z",
} as const

/** IT 305 meets MWF only; IT 205 meets TTh — used to exercise the Day filter. */
const filterableSubjects = [
  {
    type: "eligible_subject",
    subject_id: 30,
    code: "IT 305",
    title: "Systems Integration",
    units: 3,
    paired_subject_id: null,
    year_level: 3,
    semester: "1st",
    is_required: true,
    is_eligible: true,
    reasons: [{ code: "eligible", message: "All requirements are met." }],
    preference_score: 40,
    preference_reasons: ["Matches your preferred time block"],
    available_sections: [
      {
        type: "section",
        id: 30,
        academic_term_id: 2,
        subject_id: 30,
        section_code: "A",
        professor_id: 10,
        schedule_days: "MWF",
        starts_at_time: "08:00:00",
        ends_at_time: "09:00:00",
        room: "R101",
        capacity: 30,
        capacity_source: "plan",
        viability_threshold: null,
        enrolled_count: 0,
        remaining_seats: 30,
        is_block_exclusive: null,
        status: "published",
        status_label: "Published",
        college: "ccs",
        is_own_department: true,
        subject_code: "IT 305",
        subject_title: "Systems Integration",
      },
    ],
  },
  {
    type: "eligible_subject",
    subject_id: 20,
    code: "IT 205",
    title: "Web Development",
    units: 3,
    paired_subject_id: null,
    year_level: 2,
    semester: "1st",
    is_required: true,
    is_eligible: true,
    reasons: [{ code: "eligible", message: "All requirements are met." }],
    preference_score: 90,
    preference_reasons: ["Matches your preferred days"],
    available_sections: [
      {
        type: "section",
        id: 20,
        academic_term_id: 2,
        subject_id: 20,
        section_code: "A",
        professor_id: 20,
        schedule_days: "TTh",
        starts_at_time: "13:00:00",
        ends_at_time: "14:30:00",
        room: "R202",
        capacity: 30,
        capacity_source: "plan",
        viability_threshold: null,
        enrolled_count: 0,
        remaining_seats: 30,
        is_block_exclusive: null,
        status: "published",
        status_label: "Published",
        college: "ccs",
        is_own_department: true,
        subject_code: "IT 205",
        subject_title: "Web Development",
      },
    ],
  },
]

function mockIrregularSchedule(input: RequestInfo | URL) {
  const target = url(input)
  if (
    target.includes("/academic-terms") &&
    target.includes("enrollment-windows")
  )
    return Promise.resolve(
      new Response(
        JSON.stringify({
          data: {
            type: "enrollment_schedule",
            academic_term_id: 2,
            status: "semester_ongoing",
            enrollment_opens_at: null,
            enrollment_closes_at: null,
            audiences: [],
            viewer: irregularViewer,
            add_drop: addDropClosed,
          },
        }),
      ),
    )
  return null
}

function mockIrregularRoutes(overrides: { subjects?: unknown } = {}) {
  return (input: RequestInfo | URL) => {
    const scheduleResponse = mockIrregularSchedule(input)
    if (scheduleResponse) return scheduleResponse

    const target = url(input)
    if (target.includes("/academic-terms"))
      return Promise.resolve(new Response(JSON.stringify(terms)))
    if (target.includes("/eligible-subjects"))
      return Promise.resolve(
        new Response(
          JSON.stringify({ data: overrides.subjects ?? filterableSubjects }),
        ),
      )
    if (target.includes("/enrollments"))
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: [],
            links: paginationLinks,
            meta: paginationMeta,
          }),
        ),
      )
    return Promise.resolve(new Response(JSON.stringify({ data: [] })))
  }
}

function scoredBlock(overrides: {
  block_code: string
  section_id: number
  preference_score: number | null
  preference_reasons: string[]
}) {
  return {
    type: "enrollment_block",
    block_code: overrides.block_code,
    year_level: 2,
    curriculum_id: 9,
    section_plan_id: 12,
    total_units: 3,
    seats_remaining: 7,
    capacity: 40,
    is_selectable: true,
    reasons: [],
    preference_score: overrides.preference_score,
    preference_reasons: overrides.preference_reasons,
    subjects: [
      {
        section_id: overrides.section_id,
        subject_id: overrides.section_id,
        code: "CS201",
        title: "Data Structures",
        units: 3,
        schedule_days: "MWF",
        starts_at_time: "08:00:00",
        ends_at_time: "09:00:00",
        room: "LAB-1",
        modality: "f2f",
        professor_name: "Dr. Cruz",
        capacity: 40,
        enrolled_count: 33,
        remaining_seats: 7,
      },
    ],
  }
}

// IT301/IT302/IT303 exercise the ranked table: a mid score, no score at all
// (still listed — preferences rank, they never gate), and the highest score.
const scoredBlocks = [
  scoredBlock({
    block_code: "IT301",
    section_id: 31,
    preference_score: 40,
    preference_reasons: ["Matches your preferred time block"],
  }),
  scoredBlock({
    block_code: "IT302",
    section_id: 32,
    preference_score: null,
    preference_reasons: [],
  }),
  scoredBlock({
    block_code: "IT303",
    section_id: 33,
    preference_score: 90,
    preference_reasons: ["Matches your preferred days"],
  }),
]

function mockRegularSchedule(input: RequestInfo | URL) {
  const target = url(input)
  if (
    target.includes("/academic-terms") &&
    target.includes("enrollment-windows")
  )
    return Promise.resolve(
      new Response(
        JSON.stringify({
          data: {
            type: "enrollment_schedule",
            academic_term_id: 2,
            status: "semester_ongoing",
            enrollment_opens_at: null,
            enrollment_closes_at: null,
            audiences: [],
            viewer: regularViewer,
            add_drop: addDropClosed,
          },
        }),
      ),
    )
  return null
}

const paginationLinks = {
  first: "https://api.test/enrollments?page=1",
  last: "https://api.test/enrollments?page=1",
  prev: null,
  next: null,
}
const paginationMeta = {
  current_page: 1,
  last_page: 1,
  per_page: 20,
  total: 0,
}

const createdEnrollment = {
  data: {
    type: "enrollment",
    id: 9,
    student_id: 4,
    student_number: "2026-0001",
    student_name: null,
    student_year_level: null,
    student_financial_status: null,
    student_financial_status_label: null,
    academic_term_id: 2,
    status: "pending_registrar_approval",
    status_label: "Pending Registrar Approval",
    total_units: 3,
    requires_overload_approval: false,
    program_head_comment: null,
    submitted_at: "2026-07-30T00:00:00Z",
    program_head_decided_at: null,
    registrar_decided_at: null,
    payment_confirmed_at: null,
    enrolled_at: null,
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
    // No queue ticket yet at submission — it is issued only once Registrar
    // Staff approves (Phase 6).
    queue_ticket: null,
    assessment: null,
  },
}

const studentQueueView = {
  data: {
    type: "student_queue_view",
    stage: "pending_payment",
    can_claim: false,
    ticket: {
      ticket_number: "Q-LIVE-007",
      status: "waiting",
      status_label: "Waiting",
      priority: "regular",
      priority_label: "Regular",
      position: 2,
    },
    now_serving_ticket_number: "Q-LIVE-005",
    upcoming_ticket_numbers: ["Q-LIVE-006", "Q-LIVE-007"],
    cut_off_today: false,
  },
} as const

const studentAccount = {
  type: "student_account",
  student_id: 4,
  student_name: "Maria Santos",
  student_number: "2026-0001",
  year_level: 3,
  currency: "PHP",
  total_assessed: "6000.00",
  total_paid: "1000.00",
  prior_balance: "3500.00",
  outstanding_balance: "5000.00",
  has_promissory_note_on_file: true,
  entries: [
    {
      enrollment_id: 9,
      academic_term_id: 2,
      academic_term_label: "2025-2026 · 2nd",
      assessment_amount: "4500.00",
      confirmed_payment_amount: "1000.00",
      account_payment_amount: "0.00",
      outstanding_balance: "3500.00",
      promissory_note_on_file: true,
    },
  ],
} as const

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
  optionName: string | RegExp,
) {
  const triggers = await screen.findAllByLabelText(labelText)
  await user.click(triggers[0])
  await user.click(await screen.findByRole("option", { name: optionName }))
}

function ownProfile(maxUnits: number) {
  return {
    type: "student_profile",
    id: 4,
    user_id: 1,
    student_number: "2026-0001",
    name: "Maria Santos",
    first_name: "Maria",
    middle_initial: null,
    last_name: "Santos",
    suffix: null,
    email: "maria.santos@grc.com",
    address: null,
    program_id: 1,
    program_code: "BSIT",
    program_name: "BS Information Technology",
    curriculum_id: 1,
    entry_year: 2024,
    curriculum_name: "BS Information Technology Curriculum 2024-2029",
    curriculum_effective_school_year: "2024-2029",
    curriculum_max_units: maxUnits,
    curriculum_default_max_units: 30,
    year_level: 3,
    enrollment_category: "irregular",
    student_type: "freshman",
    student_type_label: "Freshman",
    admission_status: "enrolled",
    admission_status_label: "Enrolled",
    academic_standing: "good",
    academic_standing_label: "Good standing",
    financial_status: null,
    financial_status_label: null,
    requirements_verified_at: null,
    academic_setup_editable: false,
    account_setup_status: "active",
    invitation_delivery_status: "sent",
  }
}

function mockRoutes(
  overrides: {
    enrollments?: unknown
    onSubmit?: () => unknown
    queueStatus?: unknown
  } = {},
) {
  return (input: RequestInfo | URL, init?: RequestInit) => {
    const target = url(input)
    if (target.includes("/queue-status"))
      return Promise.resolve(
        new Response(JSON.stringify(overrides.queueStatus ?? studentQueueView)),
      )
    if (target.endsWith("/student-account"))
      return Promise.resolve(
        new Response(JSON.stringify({ data: studentAccount })),
      )
    if (target.includes("/academic-terms"))
      return Promise.resolve(new Response(JSON.stringify(terms)))
    if (target.includes("/eligible-subjects"))
      return Promise.resolve(
        new Response(JSON.stringify({ data: [eligibleSubject] })),
      )
    if (target.includes("/enrollments") && init?.method === "POST")
      return Promise.resolve(
        new Response(
          JSON.stringify(overrides.onSubmit?.() ?? createdEnrollment),
          { status: 201 },
        ),
      )
    if (target.includes("/enrollments"))
      return Promise.resolve(
        new Response(
          JSON.stringify(
            overrides.enrollments ?? {
              data: [],
              links: paginationLinks,
              meta: paginationMeta,
            },
          ),
        ),
      )
    return Promise.resolve(new Response(JSON.stringify({ data: [] })))
  }
}

function mockRegularRoutes(
  overrides: {
    blocks?: unknown
    onSubmit?: () => unknown
  } = {},
) {
  return (input: RequestInfo | URL, init?: RequestInit) => {
    const scheduleResponse = mockRegularSchedule(input)
    if (scheduleResponse) return scheduleResponse

    const target = url(input)
    if (target.includes("/academic-terms"))
      return Promise.resolve(new Response(JSON.stringify(terms)))
    if (target.includes("/enrollment-blocks"))
      return Promise.resolve(
        new Response(
          JSON.stringify({ data: overrides.blocks ?? [enrollmentBlock] }),
        ),
      )
    if (target.includes("/enrollments") && init?.method === "POST")
      return Promise.resolve(
        new Response(
          JSON.stringify(overrides.onSubmit?.() ?? createdEnrollment),
          { status: 201 },
        ),
      )
    if (target.includes("/enrollments"))
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: [],
            links: paginationLinks,
            meta: paginationMeta,
          }),
        ),
      )
    return Promise.resolve(new Response(JSON.stringify({ data: [] })))
  }
}

describe("EnrollmentWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => {
    vi.unstubAllGlobals()
    vi.useRealTimers()
  })

  it("selects a section, reviews, confirms, and submits the enrollment", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockRoutes())
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    await selectOption(user, "CS101 section", /Section A/)
    expect(await screen.findByText("Total units")).toBeInTheDocument()
    await user.click(screen.getByRole("button", { name: "Submit enrollment" }))
    expect(screen.getByRole("alertdialog")).toBeInTheDocument()
    await user.click(screen.getByRole("button", { name: "Confirm submission" }))

    await vi.waitFor(() =>
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringContaining("/enrollments"),
        expect.objectContaining({ method: "POST" }),
      ),
    )
    const submitCall = fetchMock.mock.calls.find(
      ([request, init]) =>
        url(request).includes("/enrollments") && init?.method === "POST",
    )
    expect(JSON.parse(submitCall?.[1]?.body as string)).toEqual({
      academic_term_id: 2,
      sections: [{ section_id: 5 }],
    })
    expect(
      await screen.findByText(/pending Program Head approval/),
    ).toBeInTheDocument()
  })

  it.each([
    ["transferee", "Transferee"],
    ["returnee", "Returnee"],
  ])(
    "holds a %s from enrolling until credit mapping is done, and offers the TOR upload and credit request",
    async (studentType, label) => {
      fetchMock.mockImplementation((input, init) => {
        const target = url(input)
        if (target.endsWith("/student-profile"))
          return Promise.resolve(
            new Response(
              JSON.stringify({
                data: {
                  ...ownProfile(30),
                  student_type: studentType,
                  student_type_label: label,
                },
              }),
            ),
          )
        if (target.includes("/eligible-subjects"))
          return Promise.resolve(
            new Response(
              JSON.stringify({
                data: [
                  {
                    ...eligibleSubject,
                    is_eligible: false,
                    reasons: [
                      {
                        code: "credit_mapping_pending",
                        message: "Credit mapping is not complete yet.",
                      },
                    ],
                  },
                ],
              }),
            ),
          )
        return mockRoutes()(input, init)
      })
      renderWithSession(<EnrollmentWorkspace />, {
        session: {
          userId: "1",
          displayName: "Student",
          role: "student",
          signedInAt: "2026-07-30T00:00:00Z",
        },
      })

      expect(
        await screen.findByText("Credit Mapping Required Before Enrollment"),
      ).toBeInTheDocument()
      expect(
        screen.getByText(/Upload your Transcript of Records/),
      ).toBeInTheDocument()
      // Both kinds of student can request credit mapping and upload their TOR.
      expect(
        screen.getByRole("button", { name: /Request Credit Mapping/ }),
      ).toBeInTheDocument()
      // And nothing can be submitted while it is open.
      expect(
        screen.queryByRole("button", { name: "Submit enrollment" }),
      ).not.toBeInTheDocument()
    },
  )

  it.each([
    {
      name: "shows the link to the Prospectus and grades to a returnee who has an enrollment deadline",
      deadline: "2026-10-21T10:00:00Z",
    },
    {
      name: "shows the link to the Prospectus and grades to a returnee without an enrollment deadline",
      deadline: null,
    },
  ])("$name", async ({ deadline }) => {
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (target.endsWith("/student-profile"))
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                ...ownProfile(30),
                student_type: "returnee",
                student_type_label: "Returnee",
                enrollment_deadline_at: deadline,
              },
            }),
          ),
        )
      if (target.includes("/eligible-subjects"))
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [
                {
                  ...eligibleSubject,
                  is_eligible: false,
                  reasons: [
                    {
                      code: "credit_mapping_pending",
                      message: "Credit mapping is not complete yet.",
                    },
                  ],
                },
              ],
            }),
          ),
        )
      return mockRoutes()(input, init)
    })
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    await screen.findByText("Credit Mapping Required Before Enrollment")
    // Grades holds the Prospectus with the credited grades, and a restricted student can open it (ADR 0050).
    expect(
      screen.getByRole("link", { name: "View Grades and Prospectus" }),
    ).toHaveAttribute("href", "/portal/grades")
    expect(
      screen.queryByRole("link", { name: "View Academic Records" }),
    ).not.toBeInTheDocument()
  })

  it("keeps the selected section after navigating away and back", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockRoutes())
    const session = {
      userId: "1",
      displayName: "Student",
      role: "student",
      signedInAt: "2026-07-30T00:00:00Z",
    } as const
    const first = renderWithSession(<EnrollmentWorkspace />, { session })

    await selectOption(user, "CS101 section", /Section A/)
    first.unmount()

    // A fresh render with its own QueryClient — nothing about this second
    // mount can read the first mount's React state or React Query cache, so
    // a restored selection here proves it came from durable storage, not an
    // in-memory artifact of the same page instance.
    renderWithSession(<EnrollmentWorkspace />, { session })

    const restoredTrigger = (
      await screen.findAllByLabelText("CS101 section")
    )[0]
    expect(restoredTrigger).toHaveTextContent(/Section A/)
  })

  it("keeps the selected block after navigating away and back", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockRegularRoutes())
    const first = renderWithSession(<EnrollmentWorkspace />, {
      session: regularStudentSession,
    })

    const section = await screen.findByRole("article", {
      name: "IT201 section",
    })
    await user.click(
      within(section).getByRole("button", { name: "Choose IT201" }),
    )
    first.unmount()

    renderWithSession(<EnrollmentWorkspace />, {
      session: regularStudentSession,
    })

    expect(
      await screen.findByRole("button", { name: "Change section" }),
    ).toBeInTheDocument()
  })

  it("refetches the eligible-subject pool immediately before submitting", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockRoutes())
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    await selectOption(user, "CS101 section", /Section A/)
    await user.click(screen.getByRole("button", { name: "Submit enrollment" }))
    await user.click(screen.getByRole("button", { name: "Confirm submission" }))
    await screen.findByText(/pending Program Head approval/)

    // Isolates the refetch this test cares about from the *separate*,
    // already-existing post-success `invalidateQueries` refetch (see
    // `mutation.onSuccess`) by only counting calls up to and including the
    // POST — a naive before/after count around the whole submit would pass
    // even without a pre-submit refetch, since the post-success one alone
    // would already grow the total.
    const postIndex = fetchMock.mock.calls.findIndex(
      ([request, init]) =>
        url(request).includes("/enrollments") && init?.method === "POST",
    )
    expect(postIndex).toBeGreaterThan(-1)
    const eligibleSubjectFetchesBeforePost = fetchMock.mock.calls
      .slice(0, postIndex)
      .filter(([request]) => url(request).includes("/eligible-subjects")).length
    expect(eligibleSubjectFetchesBeforePost).toBeGreaterThan(1)
  })

  it("explains a stale-pool conflict instead of just echoing the raw field error", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (target.includes("/enrollments") && init?.method === "POST")
        return Promise.resolve(
          new Response(
            JSON.stringify({
              error: {
                code: "VALIDATION_FAILED",
                message: "Validation failed.",
                errors: {
                  "sections.0.section_id": [
                    "This section conflicts with another section in this submission.",
                  ],
                },
                request_id: "req-1",
              },
            }),
            { status: 422 },
          ),
        )
      return mockRoutes()(input, init)
    })
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    await selectOption(user, "CS101 section", /Section A/)
    await user.click(screen.getByRole("button", { name: "Submit enrollment" }))
    await user.click(screen.getByRole("button", { name: "Confirm submission" }))

    expect(
      await screen.findByText(
        /This section conflicts with another section in this submission\./,
      ),
    ).toBeInTheDocument()
    expect(
      screen.getByText(/just refreshed against the current schedule/),
    ).toBeInTheDocument()
  })

  it("clears the persisted draft once the enrollment is submitted", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockRoutes())
    const session = {
      userId: "1",
      displayName: "Student",
      role: "student",
      signedInAt: "2026-07-30T00:00:00Z",
    } as const
    const first = renderWithSession(<EnrollmentWorkspace />, { session })

    await selectOption(user, "CS101 section", /Section A/)
    await user.click(screen.getByRole("button", { name: "Submit enrollment" }))
    await user.click(screen.getByRole("button", { name: "Confirm submission" }))
    await screen.findByText(/pending Program Head approval/)
    first.unmount()

    fetchMock.mockImplementation(
      mockRoutes({ enrollments: { data: [createdEnrollment.data] } }),
    )
    renderWithSession(<EnrollmentWorkspace />, { session })

    // The subject picker only renders pre-submission, so its absence here
    // (replaced by the submitted-status view) is what proves there is no
    // stale draft still selecting a section behind the scenes.
    expect(screen.queryByLabelText("CS101 section")).not.toBeInTheDocument()
  })

  it("preserves the selected section when submission fails", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (target.includes("/academic-terms"))
        return Promise.resolve(new Response(JSON.stringify(terms)))
      if (target.includes("/eligible-subjects"))
        return Promise.resolve(
          new Response(JSON.stringify({ data: [eligibleSubject] })),
        )
      if (target.includes("/enrollments") && init?.method === "POST")
        return Promise.resolve(
          new Response(
            JSON.stringify({
              error: {
                code: "VALIDATION_FAILED",
                message: "Validation failed.",
                errors: {
                  "sections.0.section_id": [
                    "This section is not currently eligible for selection.",
                  ],
                },
                request_id: "req-1",
              },
            }),
            { status: 422 },
          ),
        )
      if (target.includes("/enrollments"))
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [],
              links: paginationLinks,
              meta: paginationMeta,
            }),
          ),
        )
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    await selectOption(user, "CS101 section", /Section A/)
    await user.click(screen.getByRole("button", { name: "Submit enrollment" }))
    await user.click(screen.getByRole("button", { name: "Confirm submission" }))

    expect(
      await screen.findByText(
        "This section is not currently eligible for selection.",
      ),
    ).toBeInTheDocument()
    expect(screen.getAllByLabelText("CS101 section")[0]).toHaveTextContent(
      "Section A",
    )
  })

  it("shows a clear message and preserves the selection when submission conflicts", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (target.includes("/academic-terms"))
        return Promise.resolve(new Response(JSON.stringify(terms)))
      if (target.includes("/eligible-subjects"))
        return Promise.resolve(
          new Response(JSON.stringify({ data: [eligibleSubject] })),
        )
      if (target.includes("/enrollments") && init?.method === "POST")
        return Promise.resolve(
          new Response(
            JSON.stringify({
              error: {
                code: "CONFLICT",
                message:
                  "This section filled up while you were reviewing your selection.",
                errors: {},
                request_id: "request-409",
              },
            }),
            { status: 409 },
          ),
        )
      if (target.includes("/enrollments"))
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [],
              links: paginationLinks,
              meta: paginationMeta,
            }),
          ),
        )
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    await selectOption(user, "CS101 section", /Section A/)
    await user.click(screen.getByRole("button", { name: "Submit enrollment" }))
    await user.click(screen.getByRole("button", { name: "Confirm submission" }))

    expect(
      await screen.findByText(
        "This section filled up while you were reviewing your selection.",
      ),
    ).toBeInTheDocument()
    expect(screen.getAllByLabelText("CS101 section")[0]).toHaveTextContent(
      "Section A",
    )
  })

  it("shows a clear message when the eligible-subject pool cannot be reached", async () => {
    fetchMock.mockImplementation((input) => {
      const target = url(input)
      if (target.endsWith("/student-account"))
        return Promise.resolve(
          new Response(JSON.stringify({ data: studentAccount })),
        )
      if (target.includes("/academic-terms"))
        return Promise.resolve(new Response(JSON.stringify(terms)))
      if (target.includes("/eligible-subjects"))
        return Promise.reject(new TypeError("Failed to fetch"))
      if (target.includes("/enrollments"))
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [],
              links: paginationLinks,
              meta: paginationMeta,
            }),
          ),
        )
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    expect(
      await screen.findByText("Connection interrupted", {}, { timeout: 3_000 }),
    ).toBeInTheDocument()
    expect(
      screen.getByText(
        "The public enrollment API is not available right now. Confirm that the Laravel service is running, then try again.",
      ),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("button", { name: "Try again" }),
    ).toBeInTheDocument()
  })

  it("hides selection when the student already has an active enrollment this term", async () => {
    fetchMock.mockImplementation(
      mockRoutes({
        enrollments: {
          data: [
            {
              ...createdEnrollment.data,
              status: "pending_registrar_approval",
            },
          ],
          links: paginationLinks,
          meta: { ...paginationMeta, total: 1 },
        },
      }),
    )
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    expect(await screen.findByText("Enrollment #9")).toBeInTheDocument()
    expect(screen.queryByLabelText("Section")).not.toBeInTheDocument()
  })

  it("shows the overall stepper and embeds the Queue & Payment panel once an enrollment exists", async () => {
    fetchMock.mockImplementation(
      mockRoutes({
        enrollments: {
          data: [
            {
              ...createdEnrollment.data,
              status: "pending_payment",
              program_head_decided_at: null,
              registrar_decided_at: "2026-07-31T00:00:00Z",
              queue_ticket: {
                ticket_number: "Q000001",
                queue_date: "2026-07-31",
                status: "waiting",
                status_label: "Waiting",
                priority: "regular",
                priority_label: "Regular",
                position: 0,
              },
            },
          ],
          links: paginationLinks,
          meta: { ...paginationMeta, total: 1 },
        },
      }),
    )
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    expect(await screen.findByText("Enrollment #9")).toBeInTheDocument()
    expect(
      await screen.findByRole("region", { name: "Your Cashier queue" }),
    ).toHaveTextContent("Q-LIVE-007")
    // Not yet enrolled -- the Add/Drop panel only appears once `enrolled`.
    expect(
      screen.queryByRole("heading", { name: "Add/Drop requests" }),
    ).not.toBeInTheDocument()
  })

  it("shows the student's own account balance as read-only information", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockRoutes())
    renderWithSession(<EnrollmentWorkspace />, {
      session: regularStudentSession,
    })

    expect(
      await screen.findByRole("heading", { name: "Account balance" }),
    ).toBeInTheDocument()
    // Informational sections start collapsed; the section being chosen does not.
    expect(screen.queryByText("₱5,000.00")).not.toBeInTheDocument()
    await user.click(screen.getByRole("button", { name: "Account balance" }))
    expect(screen.getByText("₱5,000.00")).toBeInTheDocument()
    expect(screen.getByText("2025-2026 · 2nd")).toBeInTheDocument()
    expect(screen.getByText("Promissory note on file")).toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: /record.*payment/i }),
    ).not.toBeInTheDocument()
  })

  it("does not request a student account for a non-student session", async () => {
    fetchMock.mockImplementation(mockRoutes())
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "7",
        displayName: "Registrar Staff",
        role: "registrar_staff",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    await screen.findAllByLabelText("CS101 section")
    expect(
      fetchMock.mock.calls.some(([input]) =>
        url(input).endsWith("/student-account"),
      ),
    ).toBe(false)
  })

  it("embeds the Add/Drop panel once the enrollment is enrolled", async () => {
    fetchMock.mockImplementation(
      mockRoutes({
        enrollments: {
          data: [
            {
              ...createdEnrollment.data,
              status: "enrolled",
              program_head_decided_at: null,
              registrar_decided_at: "2026-07-31T00:00:00Z",
              payment_confirmed_at: "2026-08-01T00:00:00Z",
              enrolled_at: "2026-08-01T00:00:00Z",
            },
          ],
          links: paginationLinks,
          meta: { ...paginationMeta, total: 1 },
        },
      }),
    )
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    expect(
      await screen.findByRole("heading", { name: "Add/Drop requests" }),
    ).toBeInTheDocument()
  })

  it("opens only the step the student is on and lets every other section be expanded", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(
      mockRoutes({
        enrollments: {
          data: [
            {
              ...createdEnrollment.data,
              status: "enrolled",
              program_head_decided_at: null,
              registrar_decided_at: "2026-07-31T00:00:00Z",
              payment_confirmed_at: "2026-08-01T00:00:00Z",
              enrolled_at: "2026-08-01T00:00:00Z",
            },
          ],
          links: paginationLinks,
          meta: { ...paginationMeta, total: 1 },
        },
      }),
    )
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    const addDrop = await screen.findByRole("button", {
      name: "Add/Drop requests",
    })
    // Enrolled: the timetable is the current step and is open ...
    expect(
      screen.getByRole("button", { name: /Enrolled Class Schedule/ }),
    ).toHaveAttribute("aria-expanded", "true")
    // ... while everything else starts collapsed.
    expect(addDrop).toHaveAttribute("aria-expanded", "false")
    expect(
      screen.getByRole("button", { name: "Account balance" }),
    ).toHaveAttribute("aria-expanded", "false")
    expect(
      screen.getByRole("button", { name: "Your enrollments" }),
    ).toHaveAttribute("aria-expanded", "false")

    await user.click(addDrop)
    expect(addDrop).toHaveAttribute("aria-expanded", "true")
  })

  it("offers subject changes instead of a whole-term withdrawal once the enrollment is enrolled", async () => {
    fetchMock.mockImplementation(
      mockRoutes({
        enrollments: {
          data: [
            {
              ...createdEnrollment.data,
              status: "enrolled",
              program_head_decided_at: null,
              registrar_decided_at: "2026-07-31T00:00:00Z",
              payment_confirmed_at: "2026-08-01T00:00:00Z",
              enrolled_at: "2026-08-01T00:00:00Z",
            },
          ],
          links: paginationLinks,
          meta: { ...paginationMeta, total: 1 },
        },
      }),
    )
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    await screen.findByRole("heading", { name: "Add/Drop requests" })
    expect(
      screen.queryByRole("heading", { name: "Withdraw from this term" }),
    ).not.toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: "Withdraw" }),
    ).not.toBeInTheDocument()
  })

  it("keeps a LEC immediately followed by its paired LAB in the Enrolled Class Schedule table", async () => {
    fetchMock.mockImplementation(
      mockRoutes({
        enrollments: {
          data: [
            {
              ...createdEnrollment.data,
              status: "enrolled",
              program_head_decided_at: null,
              registrar_decided_at: "2026-07-31T00:00:00Z",
              payment_confirmed_at: "2026-08-01T00:00:00Z",
              enrolled_at: "2026-08-01T00:00:00Z",
              subjects: [
                {
                  section_id: 61,
                  subject_id: 601,
                  subject_code: "ITP1 LEC",
                  paired_subject_id: 602,
                  subject_title: "Platform Technologies Lecture",
                  status: "enrolled",
                  status_label: "Enrolled",
                },
                {
                  section_id: 62,
                  subject_id: 502,
                  subject_code: "GE101",
                  paired_subject_id: null,
                  subject_title: "Understanding the Self",
                  status: "enrolled",
                  status_label: "Enrolled",
                },
                {
                  section_id: 63,
                  subject_id: 602,
                  subject_code: "ITP1 LAB",
                  paired_subject_id: 601,
                  subject_title: "Platform Technologies Laboratory",
                  status: "enrolled",
                  status_label: "Enrolled",
                },
              ],
            },
          ],
          links: paginationLinks,
          meta: { ...paginationMeta, total: 1 },
        },
      }),
    )
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    const table = await screen.findByRole("table", {
      name: "Enrolled subjects schedule",
    })
    const rowLabels = within(table)
      .getAllByRole("row")
      .map((row) => row.textContent ?? "")
    const lecIndex = rowLabels.findIndex((text) => text.includes("ITP1 LEC"))
    const labIndex = rowLabels.findIndex((text) => text.includes("ITP1 LAB"))
    const ge101Index = rowLabels.findIndex((text) => text.includes("GE101"))

    expect(lecIndex).toBeGreaterThanOrEqual(0)
    expect(labIndex).toBe(lecIndex + 1)
    expect(ge101Index).toBeGreaterThan(labIndex)
  })

  it("shows a closed banner and disables selection and submission when the enrollment window is closed", async () => {
    fetchMock.mockImplementation((input) => {
      const target = url(input)
      if (
        target.includes("/academic-terms") &&
        target.includes("enrollment-windows")
      )
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                type: "enrollment_schedule",
                academic_term_id: 2,
                status: "semester_ongoing",
                enrollment_opens_at: null,
                enrollment_closes_at: null,
                audiences: [],
                viewer: {
                  audience: "irregular",
                  label: "Irregular Students",
                  opens_at: "2028-08-01T00:00:00Z",
                  closes_at: null,
                  is_open: false,
                  reason: "before_window",
                },
                add_drop: {
                  is_open: false,
                  reason: "enrollment_still_open",
                  reason_message:
                    "The add/drop window opens once enrollment closes for this term.",
                  opens_at: null,
                  closes_at: null,
                },
              },
            }),
          ),
        )
      if (target.includes("/academic-terms"))
        return Promise.resolve(new Response(JSON.stringify(terms)))
      if (target.includes("/eligible-subjects"))
        return Promise.resolve(
          new Response(JSON.stringify({ data: [eligibleSubject] })),
        )
      if (target.includes("/enrollments"))
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [],
              links: paginationLinks,
              meta: paginationMeta,
            }),
          ),
        )
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    expect(
      await screen.findByText("Enrollment has not opened yet"),
    ).toBeInTheDocument()
    const sectionTriggers = await screen.findAllByLabelText("CS101 section")
    for (const trigger of sectionTriggers) {
      expect(trigger).toBeDisabled()
    }
  })

  it("shows the Registrar Head opening enrollment within seconds, without a reload", async () => {
    let open = false
    const scheduleFor = (isOpen: boolean) => ({
      data: {
        type: "enrollment_schedule",
        academic_term_id: 2,
        status: "semester_ongoing",
        enrollment_opens_at: null,
        enrollment_closes_at: null,
        audiences: [],
        viewer: {
          audience: "irregular",
          label: "Irregular Students",
          opens_at: isOpen ? "2026-07-01T00:00:00Z" : "2028-08-01T00:00:00Z",
          closes_at: isOpen ? "2028-08-31T00:00:00Z" : null,
          is_open: isOpen,
          reason: isOpen ? "open" : "before_window",
        },
        add_drop: {
          is_open: false,
          reason: "enrollment_still_open",
          reason_message:
            "The add/drop window opens once enrollment closes for this term.",
          opens_at: null,
          closes_at: null,
        },
      },
    })
    fetchMock.mockImplementation((input) => {
      const target = url(input)
      if (
        target.includes("/academic-terms") &&
        target.includes("enrollment-windows")
      )
        return Promise.resolve(new Response(JSON.stringify(scheduleFor(open))))
      if (target.includes("/academic-terms"))
        return Promise.resolve(new Response(JSON.stringify(terms)))
      if (target.includes("/eligible-subjects"))
        return Promise.resolve(
          new Response(JSON.stringify({ data: [eligibleSubject] })),
        )
      if (target.includes("/enrollments"))
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [],
              links: paginationLinks,
              meta: paginationMeta,
            }),
          ),
        )
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })

    vi.useFakeTimers({ shouldAdvanceTime: true })
    try {
      renderWithSession(<EnrollmentWorkspace />, {
        session: {
          userId: "1",
          displayName: "Student",
          role: "student",
          signedInAt: "2026-07-30T00:00:00Z",
        },
      })

      expect(
        await screen.findByText("Enrollment has not opened yet"),
      ).toBeInTheDocument()

      // The Registrar Head opens enrollment; nobody on this page touches anything.
      open = true
      await act(async () => {
        await vi.advanceTimersByTimeAsync(10_000)
      })

      expect(await screen.findByText("Enrollment is open")).toBeInTheDocument()
      expect(
        screen.queryByText("Enrollment has not opened yet"),
      ).not.toBeInTheDocument()
    } finally {
      vi.useRealTimers()
    }
  })

  it("a regular student selects an inline section, confirms, and submits", async () => {
    const user = userEvent.setup()
    let submitCall: [RequestInfo | URL, RequestInit | undefined] | null = null
    fetchMock.mockImplementation((input, init) => {
      if (url(input).includes("/enrollments") && init?.method === "POST") {
        submitCall = [input, init]
      }
      return mockRegularRoutes()(input, init)
    })
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    const section = await screen.findByRole("article", {
      name: "IT201 section",
    })
    expect(screen.queryByText("Schedule preference")).not.toBeInTheDocument()
    // In thumbnail card, schedule table is hidden initially
    expect(
      within(section).queryByRole("table", { name: "IT201 schedule" }),
    ).not.toBeInTheDocument()
    expect(within(section).getByText("2 subjects")).toBeInTheDocument()

    await user.click(
      within(section).getByRole("button", { name: "Choose IT201" }),
    )

    // Schedule list appears upon selection
    const selectedSection = await screen.findByRole("article", {
      name: "IT201 section",
    })
    expect(
      within(selectedSection).getByRole("table", { name: "IT201 schedule" }),
    ).toBeInTheDocument()
    expect(within(selectedSection).getAllByText("Section ID")).not.toHaveLength(
      0,
    )
    expect(
      within(selectedSection).getAllByText("Data Structures"),
    ).not.toHaveLength(0)
    expect(within(selectedSection).getAllByText("LAB-1")).not.toHaveLength(0)

    expect(screen.getByRole("dialog")).toBeInTheDocument()
    expect(
      screen.getByRole("button", { name: "Change section" }),
    ).toBeInTheDocument()
    expect(screen.queryByText("Review your section")).not.toBeInTheDocument()
    await user.click(screen.getByRole("button", { name: "Submit enrollment" }))
    expect(
      screen.getByText(/enrolls you in all 2 subjects of section IT201/),
    ).toBeInTheDocument()
    await user.click(screen.getByRole("button", { name: "Confirm submission" }))

    await vi.waitFor(() => expect(submitCall).not.toBeNull())
    const [, init] = submitCall!
    expect(JSON.parse(init?.body as string)).toEqual({
      academic_term_id: 2,
      block_code: "IT201",
    })
    expect(await screen.findByText(/Enrollment submitted/)).toBeInTheDocument()
  })

  it("offers only the first section with seats, opens it when chosen, and goes back to that one section when changed", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockRegularRoutes({ blocks: scoredBlocks }))
    renderWithSession(<EnrollmentWorkspace />, {
      session: regularStudentSession,
    })

    const section = await screen.findByRole("article", {
      name: "IT301 section",
    })
    // The later sections stay hidden until IT301 is full.
    expect(
      screen.queryByRole("article", { name: "IT302 section" }),
    ).not.toBeInTheDocument()
    expect(
      screen.queryByRole("article", { name: "IT303 section" }),
    ).not.toBeInTheDocument()
    await user.click(
      within(section).getByRole("button", { name: "Choose IT301" }),
    )
    expect(
      screen.getByRole("article", { name: "IT301 section" }),
    ).toBeInTheDocument()

    await user.click(screen.getByRole("button", { name: "Change section" }))

    expect(
      screen.getByRole("article", { name: "IT301 section" }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("article", { name: "IT302 section" }),
    ).not.toBeInTheDocument()
  })

  it("keeps a section with no schedule-preference score selectable", async () => {
    fetchMock.mockImplementation(
      mockRegularRoutes({ blocks: [scoredBlocks[1]] }),
    )
    renderWithSession(<EnrollmentWorkspace />, {
      session: regularStudentSession,
    })

    // IT302 has no preference_score at all — it must still be listed and
    // choosable, since preferences rank sections but never gate them.
    const section = await screen.findByRole("article", {
      name: "IT302 section",
    })

    expect(
      within(section).getByRole("button", { name: "Choose IT302" }),
    ).toBeEnabled()
  })

  it("shows an explicit empty state when no sections exist for a regular student's curriculum", async () => {
    fetchMock.mockImplementation(mockRegularRoutes({ blocks: [] }))
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    expect(
      await screen.findByText(
        "No sections were generated for your year level and curriculum yet. Contact the Program Head.",
      ),
    ).toBeInTheDocument()
  })

  it("disables section selection when the enrollment window is closed", async () => {
    fetchMock.mockImplementation((input) => {
      const target = url(input)
      if (
        target.includes("/academic-terms") &&
        target.includes("enrollment-windows")
      )
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                type: "enrollment_schedule",
                academic_term_id: 2,
                status: "semester_ongoing",
                enrollment_opens_at: null,
                enrollment_closes_at: null,
                audiences: [],
                viewer: {
                  ...regularViewer,
                  is_open: false,
                  reason: "before_window",
                },
                add_drop: addDropClosed,
              },
            }),
          ),
        )
      return mockRegularRoutes()(input)
    })
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    const section = await screen.findByRole("article", {
      name: "IT201 section",
    })
    expect(
      within(section).getByRole("button", { name: "Choose IT201" }),
    ).toBeDisabled()
  })

  it("has no detectable accessibility violations once loaded", async () => {
    fetchMock.mockImplementation(mockRoutes())
    const { container } = renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    await screen.findAllByLabelText("CS101 section")
    expect(await axe(container)).toHaveNoViolations()
  })

  it("polls so a Registrar decision appears without a manual refresh", async () => {
    let status: "pending_registrar_approval" | "pending_payment" =
      "pending_registrar_approval"
    const baseRoutes = mockRoutes()
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (target.includes("/enrollments") && init?.method !== "POST")
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [
                {
                  ...createdEnrollment.data,
                  status,
                  status_label:
                    status === "pending_registrar_approval"
                      ? "Pending Registrar Approval"
                      : "Pending Payment",
                },
              ],
              links: paginationLinks,
              meta: paginationMeta,
            }),
          ),
        )
      return baseRoutes(input, init)
    })

    vi.useFakeTimers({ shouldAdvanceTime: true })

    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    expect(
      (await screen.findAllByText("Pending Registrar Approval")).length,
    ).toBeGreaterThan(0)

    status = "pending_payment"
    await act(async () => {
      await vi.advanceTimersByTimeAsync(10_000)
    })

    expect(
      (await screen.findAllByText("Pending Payment")).length,
    ).toBeGreaterThan(0)
    expect(
      screen.queryByText("Pending Registrar Approval"),
    ).not.toBeInTheDocument()
  })

  it("does not render schedule preference for an irregular student", async () => {
    fetchMock.mockImplementation(mockIrregularRoutes())
    renderWithSession(<EnrollmentWorkspace />, {
      session: irregularStudentSession,
    })

    await screen.findByRole("heading", { name: /eligible subjects/i })
    expect(screen.queryByText("Schedule preference")).not.toBeInTheDocument()
  })

  it("lets an irregular student choose a cross-department section for a shared subject", async () => {
    const user = userEvent.setup()
    const sharedSubject = {
      ...eligibleSubject,
      available_sections: [
        {
          ...eligibleSubject.available_sections[0],
          id: 55,
          college: "coe",
          is_own_department: false,
        },
      ],
    }
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (target.includes("/eligible-subjects"))
        return Promise.resolve(
          new Response(JSON.stringify({ data: [sharedSubject] })),
        )
      return mockRoutes()(input, init)
    })
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    const triggers = await screen.findAllByLabelText("CS101 section")
    await user.click(triggers[0])
    await user.click(
      await screen.findByRole("option", { name: /Section A.*COE/ }),
    )

    expect(
      screen.getAllByText("COE section — Programming 1").length,
    ).toBeGreaterThan(0)
  })

  it("instructs students to claim queuing ticket at the campus Cashier kiosk upon confirmation", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockRoutes())
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    await selectOption(user, "CS101 section", /Section A/)
    await user.click(screen.getByRole("button", { name: "Submit enrollment" }))

    expect(
      await screen.findByText(
        /proceed in person to the school Cashier kiosk on campus to claim your queuing ticket/i,
      ),
    ).toBeInTheDocument()
  })

  /** The JSON the student's submission posted to /enrollments, or null while nothing was posted. */
  const submittedBody = (): Record<string, unknown> | null => {
    const call = fetchMock.mock.calls.find(
      ([request, init]) =>
        url(request).includes("/enrollments") && init?.method === "POST",
    )
    return call
      ? (JSON.parse(call[1]?.body as string) as Record<string, unknown>)
      : null
  }

  it("warns, but still lets an irregular student submit, when units exceed the maximum", async () => {
    const user = userEvent.setup()
    const heavySubject = {
      ...eligibleSubject,
      units: 31,
    }
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (target.includes("/eligible-subjects"))
        return Promise.resolve(
          new Response(JSON.stringify({ data: [heavySubject] })),
        )
      return mockRoutes()(input, init)
    })
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    await selectOption(user, "CS101 section", /Section A/)

    // Over the maximum is a warning, not a block: Submit stays usable.
    expect(screen.getByText("Over the 30.0 unit maximum")).toBeInTheDocument()
    await user.click(screen.getByRole("button", { name: "Submit enrollment" }))

    // The student is asked whether they are sure, with the numbers, before it is sent.
    const dialog = await screen.findByRole("alertdialog")
    expect(
      within(dialog).getByText("Are you sure about these units?"),
    ).toBeInTheDocument()
    expect(
      within(dialog).getByText(
        /31 units, which is 1\.0 over the 30\.0 unit maximum/,
      ),
    ).toBeInTheDocument()

    await user.click(
      within(dialog).getByRole("button", { name: "Yes, I am sure — submit" }),
    )

    await waitFor(() => expect(submittedBody()).not.toBeNull())
    expect(submittedBody()).toMatchObject({ units_confirmed: true })
  })

  it("does not ask 'are you sure' or send a confirmation when the load is within the maximum", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) => mockRoutes()(input, init))
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    await selectOption(user, "CS101 section", /Section A/)
    await user.click(screen.getByRole("button", { name: "Submit enrollment" }))
    const dialog = await screen.findByRole("alertdialog")
    expect(
      within(dialog).queryByText("Are you sure about these units?"),
    ).not.toBeInTheDocument()
    await user.click(
      within(dialog).getByRole("button", { name: "Confirm submission" }),
    )

    await waitFor(() => expect(submittedBody()).not.toBeNull())
    expect(submittedBody()).not.toHaveProperty("units_confirmed")
  })

  it("refreshes the available-seats count without a page reload", async () => {
    const user = userEvent.setup()
    vi.useFakeTimers({ shouldAdvanceTime: true })
    let remainingSeats = 30
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (target.includes("/eligible-subjects"))
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [
                {
                  ...eligibleSubject,
                  available_sections: [
                    {
                      ...eligibleSubject.available_sections[0],
                      remaining_seats: remainingSeats,
                    },
                  ],
                },
              ],
            }),
          ),
        )
      return mockRoutes()(input, init)
    })
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    await selectOption(user, "CS101 section", /Section A/)
    expect(screen.getAllByText(/30 seats open/).length).toBeGreaterThan(0)

    remainingSeats = 5
    // useEligibleSubjectsQuery polls every 15s (Stakeholder Doc 17) so a
    // seat another student just took is reflected without a manual reload.
    await act(async () => {
      await vi.advanceTimersByTimeAsync(15_000)
    })

    expect(screen.getAllByText(/5 seats open/).length).toBeGreaterThan(0)
    expect(screen.queryByText(/30 seats open/)).not.toBeInTheDocument()
  })

  it("reports a rejected submission inside the confirm dialog and lets the student retry", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) => {
      if (url(input).includes("/enrollments") && init?.method === "POST")
        return Promise.resolve(
          new Response(
            JSON.stringify({
              error: {
                code: "VALIDATION_FAILED",
                message: "Validation failed.",
                errors: {
                  academic_term_id: [
                    "Enrollment for 2025-2026 · 2nd has closed.",
                  ],
                },
                request_id: "req-closed",
              },
            }),
            { status: 422 },
          ),
        )
      return mockRoutes()(input, init)
    })
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    await selectOption(user, "CS101 section", /Section A/)
    await user.click(screen.getByRole("button", { name: "Submit enrollment" }))
    await user.click(screen.getByRole("button", { name: "Confirm submission" }))

    // The message is inside the dialog the student is looking at, the dialog
    // stays open, and Confirm is usable again.
    const dialog = await screen.findByRole("alertdialog")
    expect(
      await within(dialog).findByText(
        "Enrollment for 2025-2026 · 2nd has closed.",
      ),
    ).toBeInTheDocument()
    expect(
      within(dialog).getByRole("button", { name: "Confirm submission" }),
    ).toBeEnabled()
  })

  it("updates a section's open-seat badge on its own when another student takes seats", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    let seatsRemaining = 7
    fetchMock.mockImplementation((input, init) => {
      if (url(input).includes("/enrollment-blocks"))
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [{ ...enrollmentBlock, seats_remaining: seatsRemaining }],
            }),
          ),
        )
      return mockRegularRoutes()(input, init)
    })
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    const section = await screen.findByRole("article", {
      name: "IT201 section",
    })
    expect(within(section).getByText("7 of 40 seats left")).toBeInTheDocument()

    // Another student takes seats; the block list is polled every 3s, so the
    // badge follows without a reload.
    seatsRemaining = 3
    await act(async () => {
      await vi.advanceTimersByTimeAsync(3_000)
    })

    const refreshed = await screen.findByRole("article", {
      name: "IT201 section",
    })
    expect(
      within(refreshed).getByText("3 of 40 seats left"),
    ).toBeInTheDocument()
    expect(screen.queryByText("7 of 40 seats left")).not.toBeInTheDocument()
  })

  it("brings up the next section on its own when the first one fills up", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    let firstIsFull = false
    const first = () =>
      firstIsFull
        ? {
            ...enrollmentBlock,
            seats_remaining: 0,
            is_selectable: false,
            reasons: [
              { code: "block_full", message: "No open seats in this section." },
            ],
          }
        : enrollmentBlock
    const second = {
      ...enrollmentBlock,
      block_code: "IT202",
      subjects: enrollmentBlock.subjects.map((subject) => ({
        ...subject,
        section_id: subject.section_id + 100,
        subject_id: subject.subject_id + 100,
      })),
    }
    fetchMock.mockImplementation((input, init) => {
      if (url(input).includes("/enrollment-blocks"))
        return Promise.resolve(
          new Response(JSON.stringify({ data: [first(), second] })),
        )
      return mockRegularRoutes()(input, init)
    })
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    await screen.findByRole("article", { name: "IT201 section" })
    expect(
      screen.queryByRole("article", { name: "IT202 section" }),
    ).not.toBeInTheDocument()

    // The last seat in IT201 goes; within one poll IT202 takes its place.
    firstIsFull = true
    await act(async () => {
      await vi.advanceTimersByTimeAsync(3_000)
    })

    expect(
      await screen.findByRole("article", { name: "IT202 section" }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("article", { name: "IT201 section" }),
    ).not.toBeInTheDocument()
  })

  it("tells a regular student when every section is full", async () => {
    const full = {
      ...enrollmentBlock,
      seats_remaining: 0,
      is_selectable: false,
      reasons: [
        { code: "block_full", message: "No open seats in this section." },
      ],
    }
    fetchMock.mockImplementation(mockRegularRoutes({ blocks: [full] }))
    renderWithSession(<EnrollmentWorkspace />, {
      session: regularStudentSession,
    })

    expect(
      await screen.findByText(/every section for your year level is full/i),
    ).toBeInTheDocument()
    expect(screen.queryByRole("article")).not.toBeInTheDocument()
  })

  it("keeps the confirm dialog honest when the chosen section disappears before submitting", async () => {
    const user = userEvent.setup()
    let blockReads = 0
    let posted = false
    fetchMock.mockImplementation((input, init) => {
      if (url(input).includes("/enrollment-blocks")) {
        blockReads += 1
        return Promise.resolve(
          new Response(
            JSON.stringify({ data: blockReads === 1 ? [enrollmentBlock] : [] }),
          ),
        )
      }
      if (url(input).includes("/enrollments") && init?.method === "POST")
        posted = true
      return mockRegularRoutes()(input, init)
    })
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    const section = await screen.findByRole("article", {
      name: "IT201 section",
    })
    await user.click(
      within(section).getByRole("button", { name: "Choose IT201" }),
    )
    await user.click(
      await screen.findByRole("button", { name: "Submit enrollment" }),
    )
    await user.click(
      await screen.findByRole("button", { name: "Confirm submission" }),
    )

    const dialog = await screen.findByRole("alertdialog")
    expect(
      await within(dialog).findByText(/Section IT201 is no longer available/),
    ).toBeInTheDocument()
    // No "0 subjects, 0 units" sentence over a live button: it says nothing is
    // selected and Confirm cannot be pressed.
    expect(dialog).not.toHaveTextContent(/0 subjects/)
    expect(dialog).toHaveTextContent(/No section is selected any more/)
    expect(
      within(dialog).getByRole("button", { name: "Confirm submission" }),
    ).toBeDisabled()
    expect(posted).toBe(false)
  })

  describe("a freshman whose payment is held", () => {
    function renderFreshman(
      freshmanStatus: string | null,
      category: "regular" | "irregular" = "regular",
      advancePaymentHeld = false,
      blocks: unknown = [enrollmentBlock],
    ) {
      fetchMock.mockImplementation((input, init) => {
        if (url(input).endsWith("/student-profile"))
          return Promise.resolve(
            new Response(
              JSON.stringify({
                data: {
                  ...ownProfile(30),
                  enrollment_category: category,
                  freshman_status: freshmanStatus,
                  advance_payment_held: advancePaymentHeld,
                  freshman_payment_confirmed_at: "2026-10-04T01:00:00Z",
                },
              }),
            ),
          )
        return mockRegularRoutes({ blocks })(input, init)
      })
      renderWithSession(<EnrollmentWorkspace />, {
        session: regularStudentSession,
      })
    }

    it("explains when every section is full without asking for another payment", async () => {
      renderFreshman("awaiting_section", "regular", false, [
        {
          ...enrollmentBlock,
          seats_remaining: 0,
          is_selectable: false,
          reasons: [{ code: "block_full", message: "No open seats." }],
        },
      ])

      expect(
        await screen.findByText("Payment received — waiting for a section"),
      ).toBeInTheDocument()
      expect(screen.getByText(/you do not pay again/i)).toBeInTheDocument()
    })

    it("shows the missing prerequisite instead of claiming a section with seats is full", async () => {
      renderFreshman("awaiting_section", "regular", false, [
        {
          ...enrollmentBlock,
          block_code: "IT101",
          seats_remaining: 40,
          is_selectable: false,
          reasons: [
            {
              code: "prerequisite",
              message: "LEAD2 requires LEAD1: The prerequisite subject has not yet been completed.",
            },
          ],
        },
      ])

      expect(
        await screen.findByText("Payment received — prerequisite needs review"),
      ).toBeInTheDocument()
      expect(screen.getByText(/ask the registrar to check your academic record/i)).toBeInTheDocument()
      expect(screen.queryByText("Payment received — waiting for a section")).not.toBeInTheDocument()
      expect(screen.getByText("Payment confirmed")).toBeInTheDocument()
      expect(screen.getByRole("button", { name: "Choose IT101" })).toBeDisabled()
    })

    it("invites a paid freshman to choose a newly available section", async () => {
      renderFreshman("awaiting_section")

      expect(
        await screen.findByText("Payment received — choose your section"),
      ).toBeInTheDocument()
      expect(screen.getByRole("button", { name: "Choose IT201" })).toBeEnabled()
    })

    it("after submitting is told the payment is on file, not to go and claim a queue number", async () => {
      const user = userEvent.setup()
      renderFreshman("awaiting_section", "regular", true)

      const section = await screen.findByRole("article", {
        name: "IT201 section",
      })
      await user.click(
        within(section).getByRole("button", { name: "Choose IT201" }),
      )
      await user.click(
        await screen.findByRole("button", { name: "Submit enrollment" }),
      )
      await user.click(
        await screen.findByRole("button", { name: "Confirm submission" }),
      )

      expect(
        await screen.findByText(
          /your payment is already on file, so once it is approved you are enrolled/i,
        ),
      ).toBeInTheDocument()
      expect(
        screen.queryByText(/claim your queuing ticket/i),
      ).not.toBeInTheDocument()
    })

    it("an irregular freshman is told to choose subjects, not that every section is full", async () => {
      renderFreshman("awaiting_section", "irregular")

      expect(
        await screen.findByText("Payment received — choose your subjects"),
      ).toBeInTheDocument()
      expect(
        screen.queryByText("Payment received — waiting for a section"),
      ).not.toBeInTheDocument()
      expect(
        screen.queryByText(/every section for your year level/i),
      ).not.toBeInTheDocument()
      expect(screen.getByText(/you do not pay again/i)).toBeInTheDocument()
    })

    it("sees no such banner when enrolling normally or when not a freshman in that state", async () => {
      renderFreshman(null)

      await screen.findByRole("article", { name: "IT201 section" })
      expect(
        screen.queryByText("Payment received — waiting for a section"),
      ).not.toBeInTheDocument()
    })
  })

  it("uses the student's own curriculum unit cap instead of a fixed 30", async () => {
    const user = userEvent.setup()
    let posted = false
    const heavySubject = { ...eligibleSubject, units: 32 }
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (target.endsWith("/student-profile"))
        return Promise.resolve(
          new Response(JSON.stringify({ data: ownProfile(41.5) })),
        )
      if (target.includes("/eligible-subjects"))
        return Promise.resolve(
          new Response(JSON.stringify({ data: [heavySubject] })),
        )
      if (target.includes("/enrollments") && init?.method === "POST")
        posted = true
      return mockRoutes()(input, init)
    })
    renderWithSession(<EnrollmentWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-30T00:00:00Z",
      },
    })

    await selectOption(user, "CS101 section", /Section A/)
    // 32 units is over the old fixed 30 but within this curriculum's 41.5.
    expect(screen.queryByText(/Exceeds/)).not.toBeInTheDocument()
    await user.click(screen.getByRole("button", { name: "Submit enrollment" }))
    await user.click(screen.getByRole("button", { name: "Confirm submission" }))

    await vi.waitFor(() => expect(posted).toBe(true))
    expect(
      screen.queryByText(/exceeds the maximum allowed limit/),
    ).not.toBeInTheDocument()
  })

  // A transferee pays first too (ADR 0043, amended 2026-10-05): once their credit mapping closes
  // they hold a queue number, choose their subjects, and the enrollment waits for the Cashier.
  describe("a transferee who pays first", () => {
    const approvedCredit = {
      type: "transferee_credit",
      id: 1,
      student_id: 4,
      student_number: "2026-0001",
      student_name: "Maria Santos",
      source_institution: "Other University",
      source_subject_code: "MATH101",
      source_subject_title: "Calculus 1",
      source_grade: "1.75",
      credited_units: 3,
      source_school_year: "2023-2024",
      source_semester: "1st",
      subject_id: 10,
      subject_code: "CS101",
      subject_title: "Introduction to Computing",
      requested_by_student: true,
      status: "approved",
      status_label: "Approved",
      endorsed_at: "2026-10-02T00:00:00Z",
      processed_at: "2026-10-03T00:00:00Z",
      created_at: "2026-10-01T00:00:00Z",
    }
    const queueNumber = {
      data: {
        type: "student_queue_view",
        stage: "no_active_enrollment",
        can_claim: false,
        ticket: {
          ticket_number: "Q007",
          status: "waiting",
          status_label: "Waiting",
          priority: "regular",
          priority_label: "Regular",
          position: 2,
        },
        now_serving_ticket_number: "Q005",
        upcoming_ticket_numbers: ["Q006", "Q007"],
        cut_off_today: false,
      },
    }
    const holdEnrollment = {
      ...createdEnrollment.data,
      status: "awaiting_first_payment",
      status_label: "Awaiting First Payment",
      queue_ticket: null,
    }

    function renderTransferee({
      enrollments = [],
      queue = queueNumber,
      paid = false,
    }: { enrollments?: unknown[]; queue?: unknown; paid?: boolean } = {}) {
      fetchMock.mockImplementation((input, init) => {
        const target = url(input)
        if (target.endsWith("/student-profile"))
          return Promise.resolve(
            new Response(
              JSON.stringify({
                data: {
                  ...ownProfile(30),
                  enrollment_category: "regular",
                  student_type: "transferee",
                  student_type_label: "Transferee",
                  freshman_payment_confirmed_at: paid
                    ? "2026-10-05T01:00:00Z"
                    : null,
                  // The held first payment, until the Registrar's approval applies it.
                  advance_payment_held: paid,
                },
              }),
            ),
          )
        if (target.includes("/transferee-credits"))
          return Promise.resolve(
            new Response(
              JSON.stringify({
                data: [approvedCredit],
                links: paginationLinks,
                meta: { ...paginationMeta, total: 1 },
              }),
            ),
          )
        if (target.includes("/queue-status"))
          return Promise.resolve(new Response(JSON.stringify(queue)))
        if (target.includes("/enrollments") && !init?.method)
          return Promise.resolve(
            new Response(
              JSON.stringify({
                data: enrollments,
                links: paginationLinks,
                meta: { ...paginationMeta, total: enrollments.length },
              }),
            ),
          )
        return mockRegularRoutes()(input, init)
      })
      return renderWithSession(<EnrollmentWorkspace />, {
        session: regularStudentSession,
      })
    }

    it("sees their queue number while they are still choosing their subjects", async () => {
      renderTransferee()

      await screen.findByRole("article", { name: "IT201 section" })
      expect(
        await screen.findByRole("heading", { name: "Your queue number" }),
      ).toBeInTheDocument()
      expect(screen.getAllByText("Q007").length).toBeGreaterThan(0)
      expect(
        screen.getByText(
          /choose your subjects below, then take this number to the Cashier/i,
        ),
      ).toBeInTheDocument()
    })

    it("who paid before choosing is told the payment is on file and to choose their subjects", async () => {
      renderTransferee({
        paid: true,
        queue: { data: { ...queueNumber.data, ticket: null } },
      })

      expect(
        await screen.findByText("Payment received — choose your subjects"),
      ).toBeInTheDocument()
      expect(screen.getByText(/you do not pay again/i)).toBeInTheDocument()
      // Paid already: no stepper step asks them to pay at the Cashier again.
      expect(
        screen.queryByText("Payment at the Cashier"),
      ).not.toBeInTheDocument()
    })

    it("sees no queue number card before the Cashier has given them one", async () => {
      renderTransferee({
        queue: { data: { ...queueNumber.data, ticket: null } },
      })

      await screen.findByRole("article", { name: "IT201 section" })
      expect(
        screen.queryByRole("heading", { name: "Your queue number" }),
      ).not.toBeInTheDocument()
    })

    it("shows payment at the Cashier as the step they are on while their subjects wait at the hold", async () => {
      renderTransferee({
        enrollments: [holdEnrollment],
        queue: {
          data: { ...queueNumber.data, stage: "awaiting_first_payment" },
        },
      })

      const label = await screen.findByText("Payment at the Cashier")
      const stages = Array.from(
        label.closest("ol")?.querySelectorAll("li") ?? [],
      ).map((item) => item.textContent?.trim().replace(/^\d+/, ""))
      expect(stages).toEqual([
        "Select section",
        "Submitted",
        "Payment at the Cashier",
        "Registrar approved",
        "Enrolled",
      ])
      expect(screen.queryByText("Payment confirmed")).not.toBeInTheDocument()
    })

    it("after submitting is told to take their number to the Cashier, not to claim a ticket at the kiosk", async () => {
      const user = userEvent.setup()
      renderTransferee()

      const section = await screen.findByRole("article", {
        name: "IT201 section",
      })
      await user.click(
        within(section).getByRole("button", { name: "Choose IT201" }),
      )
      await user.click(
        await screen.findByRole("button", { name: "Submit enrollment" }),
      )
      await user.click(
        await screen.findByRole("button", { name: "Confirm submission" }),
      )

      expect(
        await screen.findByText(
          /take your queue number to the school Cashier and make your first payment/i,
        ),
      ).toBeInTheDocument()
      expect(
        screen.queryByText(/claim your queuing ticket/i),
      ).not.toBeInTheDocument()
    })
  })
})
