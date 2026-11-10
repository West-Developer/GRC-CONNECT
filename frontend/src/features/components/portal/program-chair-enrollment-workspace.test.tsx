import {
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from "@testing-library/react"
import { QueryClientProvider } from "@tanstack/react-query"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { ProgramChairEnrollmentWorkspace } from "@/features/components/portal/program-chair-enrollment-workspace"
import {
  AuthContext,
  type AuthContextValue,
} from "@/features/auth/auth-context-value"
import { renderWithSession } from "@/tests/render-app"

// Most cases here walk the whole four-year wizard, typing into each year's
// fields and awaiting a plan save per step, before they even reach the
// behaviour under test. That is far more interaction than the 5s default
// allows on a loaded machine — the assertions themselves pass in well
// under a second once the workspace is in the right state.
vi.setConfig({ testTimeout: 25_000 })

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
} as const
const curricula = {
  data: [
    {
      type: "curriculum",
      id: 9,
      program_id: 1,
      name: "BSCS 2026",
      effective_school_year: "2026-2027",
      status: "active",
      status_label: "Active",
      decided_at: null,
      last_decision_reason: null,
      subjects: [
        {
          subject_id: 11,
          code: "CS101",
          title: "Programming 1",
          units: 3,
          year_level: 1,
          semester: "1st",
          is_required: true,
          prerequisites: [],
        },
      ],
    },
    {
      type: "curriculum",
      id: 10,
      program_id: 1,
      name: "BSCS 2024",
      effective_school_year: "2024-2025",
      status: "active",
      status_label: "Active",
      decided_at: null,
      last_decision_reason: null,
      subjects: [
        {
          subject_id: 11,
          code: "CS101",
          title: "Programming 1",
          units: 3,
          year_level: 1,
          semester: "1st",
          is_required: true,
          prerequisites: [],
        },
      ],
    },
  ],
} as const
const plans = {
  data: [1, 2, 3, 4].map((year) => ({
    type: "academic-term-section-plan",
    id: year,
    academic_term_id: 2,
    curriculum_id: 9,
    college: "ccs",
    year_level: year,
    section_count: 0,
    students_per_block: 40,
    status: "draft",
    status_label: "Draft",
    submitted_at: null,
  })),
} as const
const sections = {
  data: [
    {
      type: "section",
      id: 21,
      academic_term_id: 2,
      section_plan_id: 1,
      subject_id: 11,
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
      is_block_exclusive: null,
      status: "planned",
      status_label: "Planned",
    },
    {
      type: "section",
      id: 22,
      academic_term_id: 2,
      section_plan_id: 2,
      subject_id: 11,
      section_code: "IT201",
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
      is_block_exclusive: null,
      status: "planned",
      status_label: "Planned",
    },
    {
      type: "section",
      id: 23,
      academic_term_id: 2,
      section_plan_id: 2,
      subject_id: 11,
      section_code: "2A",
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
      is_block_exclusive: null,
      status: "planned",
      status_label: "Planned",
    },
  ],
} as const
const completedSections = {
  data: sections.data.map((section) => ({
    ...section,
    professor_id: 12,
    schedule_days: "M",
    starts_at_time: "08:00:00",
    ends_at_time: "09:00:00",
    room: "LAB 1",
    modality: "f2f" as const,
  })),
}
// A section with everything except its professor filled in must still be
// submittable — the Chair can decide who teaches it after Dean approval.
const sectionsMissingProfessorOnly = {
  data: sections.data.map((section) => ({
    ...section,
    professor_id: null,
    schedule_days: "M",
    starts_at_time: "08:00:00",
    ends_at_time: "09:00:00",
    room: "LAB 1",
    modality: "f2f" as const,
  })),
}

function url(input: RequestInfo | URL) {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : input.url
}

const completedGenerationRun = {
  data: {
    type: "schedule_generation_run",
    id: 90,
    academic_term_id: 2,
    prediction_run_id: 44,
    college: "ccs",
    status: "succeeded",
    warnings: [],
    error_summary: null,
    started_at: "2026-08-09T00:00:00Z",
    completed_at: "2026-08-09T00:00:03Z",
    created_at: "2026-08-09T00:00:00Z",
    model: {
      strategy: "random_forest",
      model_version: "test-rf",
      training_observation_count: 12,
      mae: 1,
      rmse: 1,
    },
    forecasts: [],
    section_block_recommendations: [],
    faculty_load: {
      academic_term_id: 2,
      college: "ccs",
      threshold_units: null,
      required_teaching_units: 0,
      required_assignments: 0,
      equivalent_faculty_loads: null,
      assigned_count: 0,
      unassigned_count: 0,
      overloaded_count: 0,
      faculty: [],
      unassigned: [],
    },
  },
} as const

function mockAll({
  holdSectionRefresh = false,
  failScheduleSave = false,
  completeSchedules = false,
  missingProfessorOnly = false,
  failSubmit = false,
  failProtectedBlockReduction = false,
  returnedRemark = "",
  proposal = null as null | Record<string, unknown>,
  restoredPlans = false,
  restoredDraftPlans = false,
  latestGenerationRun = null as null | typeof completedGenerationRun,
} = {}) {
  let scheduleSaved = false

  return (input: RequestInfo | URL, init?: RequestInit) => {
    const target = url(input)
    if (target.includes("/sections/21") && init?.method === "PATCH") {
      if (failScheduleSave)
        return Promise.resolve(
          new Response(
            JSON.stringify({
              error: {
                code: "VALIDATION_FAILED",
                message: "The submitted schedule has a conflict.",
                errors: {
                  schedule_days: [
                    "This professor already has a class at this time.",
                  ],
                },
                request_id: "test-request",
              },
            }),
            { status: 422 },
          ),
        )
      scheduleSaved = true
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: {
              ...sections.data[0],
              professor_id: 12,
              schedule_days: "M",
              starts_at_time: "08:00:00",
              ends_at_time: "09:00:00",
              room: "LAB 1",
              modality: "f2f",
            },
          }),
        ),
      )
    }
    if (target.includes("/section-plan/submit") && init?.method === "POST") {
      if (failSubmit)
        return Promise.resolve(
          new Response(
            JSON.stringify({
              error: {
                code: "VALIDATION_FAILED",
                message: "The submitted data is invalid.",
                errors: {
                  sections: ["The approval submission could not be completed."],
                },
                request_id: "submit-test",
              },
            }),
            { status: 422 },
          ),
        )
      return Promise.resolve(
        new Response(JSON.stringify({ data: { id: 30, status: "draft" } })),
      )
    }
    if (target.includes("/schedule-proposals"))
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: proposal
              ? [proposal]
              : returnedRemark
                ? [
                    {
                      type: "schedule_proposal",
                      id: 30,
                      academic_term_id: 2,
                      submitted_by: 4,
                      submitted_by_name: "Chair",
                      college: "ccs",
                      college_label: "College of Computer Studies",
                      academic_term_label: "2026-2027 · 1st",
                      is_submitted: false,
                      is_returned: true,
                      returned_by_role: "dean",
                      status: "draft",
                      status_label: "Draft",
                      decided_by: 5,
                      decided_by_name: "Dean Reyes",
                      decided_at: "2026-08-02T05:00:00Z",
                      decision_reason: returnedRemark,
                      decision_history: [
                        {
                          action: "dean_return",
                          action_label: "Returned by Dean",
                          actor_name: "Dean Reyes",
                          actor_role: "dean",
                          decided_at: "2026-08-02T05:00:00Z",
                          notes: returnedRemark,
                        },
                      ],
                    },
                  ]
                : [],
          }),
        ),
      )
    if (
      target.includes("/academic-term-section-plans") ||
      target.includes("/section-plan")
    ) {
      if (
        failProtectedBlockReduction &&
        init?.method === "PATCH" &&
        typeof init.body === "string" &&
        /"1":0(?=[,}])/u.test(init.body)
      )
        return Promise.resolve(
          new Response(
            JSON.stringify({
              error: {
                code: "VALIDATION_FAILED",
                message: "The section count could not be saved.",
                errors: {
                  counts: [
                    "Cannot reduce 1th-year sections below 2 while IT102 has assigned schedule or enrollment data.",
                  ],
                },
                request_id: "section-count-test",
              },
            }),
            { status: 422 },
          ),
        )
      return Promise.resolve(
        new Response(
          JSON.stringify(
            restoredDraftPlans
              ? {
                  data: plans.data.map((plan) => ({
                    ...plan,
                    section_count: 1,
                    status: "draft",
                    status_label: "Draft",
                  })),
                }
              : restoredPlans
                ? {
                    data: plans.data.map((plan) => ({
                      ...plan,
                      section_count: 1,
                      status: "submitted",
                      status_label: "Submitted",
                      submitted_at: "2026-08-02T05:00:00Z",
                    })),
                  }
                : plans,
          ),
        ),
      )
    }
    if (target.includes("/schedule-generation-runs/latest"))
      return Promise.resolve(
        new Response(JSON.stringify(latestGenerationRun ?? { data: null })),
      )
    if (target.includes("/academic-terms"))
      return Promise.resolve(new Response(JSON.stringify(terms)))
    if (target.includes("/curricula"))
      return Promise.resolve(new Response(JSON.stringify(curricula)))
    if (target.includes("/sections")) {
      if (holdSectionRefresh && scheduleSaved)
        return new Promise<Response>(() => undefined)
      return Promise.resolve(
        new Response(
          JSON.stringify(
            completeSchedules
              ? completedSections
              : missingProfessorOnly
                ? sectionsMissingProfessorOnly
                : sections,
          ),
        ),
      )
    }
    if (target.includes("/faculty-members"))
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: [
              {
                type: "faculty_member",
                id: 12,
                name: "ANGAC",
                college: "ccs",
                status: "active",
                status_label: "Active",
                employment_type: "full_time",
                employment_type_label: "Full-time",
                planning_unit_reference: 18,
                is_assignable: true,
              },
            ],
          }),
        ),
      )
    if (target.includes("/room-options"))
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: [{ type: "room_option", id: 4, name: "LAB 1" }],
          }),
        ),
      )
    if (target.includes("/faculty-availabilities"))
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    if (target.includes("/faculty-subject-preferences"))
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    if (init?.method === "PATCH" || init?.method === "POST")
      return Promise.resolve(new Response(JSON.stringify({ data: plans.data })))
    return Promise.resolve(new Response(JSON.stringify({ data: [] })))
  }
}

function renderWorkspace() {
  return renderWithSession(<ProgramChairEnrollmentWorkspace />, {
    session: {
      userId: "4",
      displayName: "Chair",
      role: "program_chair",
      college: "ccs",
      signedInAt: "2026-07-29T12:00:00Z",
    },
  })
}

function renderWorkspaceWithClient(
  queryClient: ReturnType<typeof renderWorkspace>["queryClient"],
) {
  const value: AuthContextValue = {
    session: {
      userId: "4",
      displayName: "Chair",
      role: "program_chair",
      college: "ccs",
      signedInAt: "2026-07-29T12:00:00Z",
    },
    signIn: () =>
      Promise.resolve({
        userId: "4",
        displayName: "Chair",
        role: "program_chair",
        college: "ccs",
        signedInAt: "2026-07-29T12:00:00Z",
      }),
    verifyLoginOtp: () =>
      Promise.reject(new Error("verifyLoginOtp: not implemented in stub")),
    resendLoginOtp: () =>
      Promise.reject(new Error("resendLoginOtp: not implemented in stub")),
    signInWithGoogle: () =>
      Promise.reject(new Error("signInWithGoogle: not implemented in stub")),
    signOut: () => undefined,
    replaceSession: () => undefined,
    status: "authenticated",
    storageAvailable: true,
  }

  return render(
    <QueryClientProvider client={queryClient}>
      <AuthContext.Provider value={value}>
        <ProgramChairEnrollmentWorkspace />
      </AuthContext.Provider>
    </QueryClientProvider>,
  )
}

function labelFor(year: number) {
  return `${year}${year === 1 ? "st" : year === 2 ? "nd" : year === 3 ? "rd" : "th"} Year`
}

async function chooseCurriculum(
  user: ReturnType<typeof userEvent.setup>,
  year: number,
) {
  await user.click(screen.getByLabelText(`Curriculum for ${labelFor(year)}`))
  await user.click(await screen.findByRole("option", { name: /BSCS 2026/ }))
}

describe("ProgramChairEnrollmentWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("keeps the latest Demand Forecast immediately available after returning to Enrollment", async () => {
    fetchMock.mockImplementation(
      mockAll({ latestGenerationRun: completedGenerationRun }),
    )
    const first = renderWorkspace()

    await waitFor(() =>
      expect(
        screen.getByRole("button", { name: "Demand Forecast" }),
      ).toBeEnabled(),
    )
    first.unmount()

    renderWorkspaceWithClient(first.queryClient)

    expect(
      screen.getByRole("button", { name: "Demand Forecast" }),
    ).toBeEnabled()
  })

  it("explains a generated section with no matching forecast as manually planned", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(
      mockAll({ latestGenerationRun: completedGenerationRun }),
    )
    renderWorkspace()

    await user.click(
      await screen.findByRole("button", { name: "Demand Forecast" }),
    )
    await user.click(
      await screen.findByRole("button", { name: "View explanations" }),
    )

    expect(
      await screen.findByRole("region", {
        name: "Why these sections were generated",
      }),
    ).toHaveTextContent(
      "Manually planned by the Program Head — no demand forecast was available for this subject.",
    )
  })

  it("guides the chair through one year level at a time", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockAll())
    renderWorkspace()
    expect(
      await screen.findByRole("heading", { name: "1st Year sections" }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("button", { name: "Save and continue" }),
    ).toBeDisabled()
    await chooseCurriculum(user, 1)
    await user.clear(screen.getByLabelText("Number of block sections"))
    await user.type(screen.getByLabelText("Number of block sections"), "2")
    await user.click(screen.getByRole("button", { name: "Save and continue" }))
    expect(
      await screen.findByRole("heading", { name: "2nd Year sections" }),
    ).toBeInTheDocument()
  })

  it("highlights the active year level red in the progress bar", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockAll())
    renderWorkspace()
    expect(
      await screen.findByRole("heading", { name: "1st Year sections" }),
    ).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "1st Year" })).toHaveAttribute(
      "data-variant",
      "destructive",
    )
    expect(screen.getByRole("button", { name: "2nd Year" })).toHaveAttribute(
      "data-variant",
      "outline",
    )

    await chooseCurriculum(user, 1)
    await user.clear(screen.getByLabelText("Number of block sections"))
    await user.type(screen.getByLabelText("Number of block sections"), "2")
    await user.click(screen.getByRole("button", { name: "Save and continue" }))

    expect(
      await screen.findByRole("heading", { name: "2nd Year sections" }),
    ).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "1st Year" })).toHaveAttribute(
      "data-variant",
      "outline",
    )
    expect(screen.getByRole("button", { name: "2nd Year" })).toHaveAttribute(
      "data-variant",
      "destructive",
    )
  })

  it("shows review controls before releasing the subject list", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockAll())
    renderWorkspace()
    for (let year = 1; year <= 4; year++) {
      await chooseCurriculum(user, year)
      const input = await screen.findByLabelText("Number of block sections")
      await user.clear(input)
      await user.type(input, "1")
      await user.click(
        screen.getByRole("button", {
          name: year === 4 ? "Continue to review" : "Save and continue",
        }),
      )
    }
    expect(
      await screen.findByRole("heading", { name: "Review block sections" }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("button", { name: "Generate subject list" }),
    ).toBeInTheDocument()
  })

  it("filters generated block tables by year without numbered step labels", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockAll())
    renderWorkspace()
    for (let year = 1; year <= 4; year++) {
      await chooseCurriculum(user, year)
      const input = await screen.findByLabelText("Number of block sections")
      await user.clear(input)
      await user.type(input, "1")
      await user.click(
        screen.getByRole("button", {
          name: year === 4 ? "Continue to review" : "Save and continue",
        }),
      )
    }
    await user.click(
      screen.getByRole("button", { name: "Generate subject list" }),
    )
    expect(
      await screen.findByRole("tab", { name: "1st Year" }),
    ).toBeInTheDocument()
    expect(screen.getByText("IT101")).toBeInTheDocument()
    expect(screen.queryByText("IT201")).not.toBeInTheDocument()
    expect(screen.queryByText("2A")).not.toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: "1. 1st Year" }),
    ).not.toBeInTheDocument()

    // Open the IT101 schedule modal to inspect the subject table
    await user.click(screen.getByRole("button", { name: "View schedule & assign" }))
    expect(screen.getByRole("columnheader", { name: "Professor" })).toBeInTheDocument()
    expect(screen.getByText("3")).toBeInTheDocument()
  })

  it("provides generated subjects inside the modal schedule viewer with schedule assignment", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockAll())
    renderWorkspace()
    for (let year = 1; year <= 4; year++) {
      await chooseCurriculum(user, year)
      const input = await screen.findByLabelText("Number of block sections")
      await user.clear(input)
      await user.type(input, "1")
      await user.click(
        screen.getByRole("button", {
          name: year === 4 ? "Continue to review" : "Save and continue",
        }),
      )
    }
    await user.click(
      screen.getByRole("button", { name: "Generate subject list" }),
    )

    const sectionCard = await screen.findByRole("article", {
      name: "IT101 section",
    })
    expect(sectionCard).toBeInTheDocument()

    await user.click(within(sectionCard).getByRole("button", { name: "View schedule & assign" }))

    const modal = screen.getByRole("dialog", { name: /IT101 Schedule/i })
    expect(modal).toBeInTheDocument()
    expect(within(modal).getByText("Programming 1")).toBeInTheDocument()
    expect(within(modal).getByText("21")).toBeInTheDocument()
    expect(
      within(modal).getByRole("button", { name: "Assign schedule" }),
    ).toBeInTheDocument()
  })

  it("opens a focused schedule assignment dialog from a generated subject row", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockAll())
    renderWorkspace()
    for (let year = 1; year <= 4; year++) {
      await chooseCurriculum(user, year)
      const input = await screen.findByLabelText("Number of block sections")
      await user.clear(input)
      await user.type(input, "1")
      await user.click(
        screen.getByRole("button", {
          name: year === 4 ? "Continue to review" : "Save and continue",
        }),
      )
    }
    await user.click(
      screen.getByRole("button", { name: "Generate subject list" }),
    )
    await user.click(
      await screen.findByRole("button", { name: "View schedule & assign" }),
    )
    await user.click(
      await screen.findByRole("button", { name: "Assign schedule" }),
    )
    expect(await screen.findByRole("dialog")).toHaveTextContent("IT101")
    expect(screen.getByLabelText("Professor")).toBeInTheDocument()
    expect(
      screen.getByRole("button", { name: /Select a room|Change room & schedule/ }),
    ).toBeInTheDocument()
    expect(screen.queryByText(/Google Classroom/i)).not.toBeInTheDocument()
  })

  it("opens weekly calendar dialog from a block section and displays the class timetable", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockAll())
    renderWorkspace()
    for (let year = 1; year <= 4; year++) {
      await chooseCurriculum(user, year)
      const input = await screen.findByLabelText("Number of block sections")
      await user.clear(input)
      await user.type(input, "1")
      await user.click(
        screen.getByRole("button", {
          name: year === 4 ? "Continue to review" : "Save and continue",
        }),
      )
    }
    await user.click(
      screen.getByRole("button", { name: "Generate subject list" }),
    )
    const viewCalendarButton = await screen.findByRole("button", {
      name: "View schedule & assign",
    })
    await user.click(viewCalendarButton)

    const dialog = await screen.findByRole("dialog", { name: /IT101 Schedule/i })
    expect(dialog).toBeInTheDocument()
    expect(
      within(dialog).getByRole("radio", { name: "Calendar view" }),
    ).toBeInTheDocument()
    expect(
      within(dialog).getByRole("radio", { name: "Table view" }),
    ).toBeInTheDocument()
  })

  it("offers only the chair's seeded room options in the schedule dialog", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockAll())
    renderWorkspace()
    for (let year = 1; year <= 4; year++) {
      await chooseCurriculum(user, year)
      const input = await screen.findByLabelText("Number of block sections")
      await user.clear(input)
      await user.type(input, "1")
      await user.click(
        screen.getByRole("button", {
          name: year === 4 ? "Continue to review" : "Save and continue",
        }),
      )
    }
    await user.click(
      screen.getByRole("button", { name: "Generate subject list" }),
    )
    await user.click(
      await screen.findByRole("button", { name: "View schedule & assign" }),
    )
    await user.click(
      await screen.findByRole("button", { name: "Assign schedule" }),
    )
    await user.click(screen.getByPlaceholderText("Search room"))
    await user.click(await screen.findByRole("option", { name: "LAB 1" }))
    expect(screen.getByPlaceholderText("Search room")).toHaveValue("LAB 1")
  })

  it("filters professors as the chair types in the schedule dialog", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockAll())
    renderWorkspace()
    for (let year = 1; year <= 4; year++) {
      await chooseCurriculum(user, year)
      const input = await screen.findByLabelText("Number of block sections")
      await user.clear(input)
      await user.type(input, "1")
      await user.click(
        screen.getByRole("button", {
          name: year === 4 ? "Continue to review" : "Save and continue",
        }),
      )
    }
    await user.click(
      screen.getByRole("button", { name: "Generate subject list" }),
    )
    await user.click(
      await screen.findByRole("button", { name: "View schedule & assign" }),
    )
    await user.click(
      await screen.findByRole("button", { name: "Assign schedule" }),
    )
    await user.click(screen.getByPlaceholderText("Search professor"))
    await user.click(await screen.findByRole("option", { name: "ANGAC" }))
    expect(screen.getByPlaceholderText("Search professor")).toHaveValue("ANGAC")
  })

  it("closes the schedule modal after a successful save even while the section list refreshes", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockAll({ holdSectionRefresh: true }))
    renderWorkspace()
    for (let year = 1; year <= 4; year++) {
      await chooseCurriculum(user, year)
      const input = await screen.findByLabelText("Number of block sections")
      await user.clear(input)
      await user.type(input, "1")
      await user.click(
        screen.getByRole("button", {
          name: year === 4 ? "Continue to review" : "Save and continue",
        }),
      )
    }
    await user.click(
      screen.getByRole("button", { name: "Generate subject list" }),
    )
    await user.click(
      await screen.findByRole("button", { name: "View schedule & assign" }),
    )
    await user.click(
      await screen.findByRole("button", { name: "Assign schedule" }),
    )
    await user.click(screen.getByPlaceholderText("Search professor"))
    await user.click(await screen.findByRole("option", { name: "ANGAC" }))
    await user.click(screen.getByPlaceholderText("Search room"))
    await user.click(await screen.findByRole("option", { name: "LAB 1" }))
    await user.click(screen.getByLabelText("Day"))
    await user.click(await screen.findByRole("option", { name: "M" }))
    fireEvent.change(screen.getByLabelText("Start time"), {
      target: { value: "08:00" },
    })
    fireEvent.change(screen.getByLabelText("End time"), {
      target: { value: "09:00" },
    })
    await user.click(screen.getByRole("button", { name: "Save schedule" }))

    await waitFor(() =>
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument(),
    )
  })

  it("shows an invalid time order inside the modal without sending a section update", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockAll())
    renderWorkspace()
    for (let year = 1; year <= 4; year++) {
      await chooseCurriculum(user, year)
      const input = await screen.findByLabelText("Number of block sections")
      await user.clear(input)
      await user.type(input, "1")
      await user.click(
        screen.getByRole("button", {
          name: year === 4 ? "Continue to review" : "Save and continue",
        }),
      )
    }
    await user.click(
      screen.getByRole("button", { name: "Generate subject list" }),
    )
    await user.click(
      await screen.findByRole("button", { name: "View schedule & assign" }),
    )
    await user.click(
      await screen.findByRole("button", { name: "Assign schedule" }),
    )
    await user.click(screen.getByLabelText("Day"))
    await user.click(await screen.findByRole("option", { name: "T" }))
    fireEvent.change(screen.getByLabelText("Start time"), {
      target: { value: "13:17" },
    })
    fireEvent.change(screen.getByLabelText("End time"), {
      target: { value: "12:15" },
    })
    await user.click(screen.getByRole("button", { name: "Save schedule" }))

    const dialog = screen.getByRole("dialog")
    expect(dialog).toHaveTextContent("End time must be after start time.")
    expect(screen.getByLabelText("Start time")).toHaveAttribute(
      "aria-invalid",
      "true",
    )
    expect(screen.getByLabelText("End time")).toHaveAttribute(
      "aria-invalid",
      "true",
    )
    expect(
      fetchMock.mock.calls.filter(
        ([input, init]) =>
          url(input).includes("/sections/21") && init?.method === "PATCH",
      ),
    ).toHaveLength(0)
  })

  it("allows approval submission while incomplete schedule details remain", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockAll())
    renderWorkspace()
    for (let year = 1; year <= 4; year++) {
      await chooseCurriculum(user, year)
      const input = await screen.findByLabelText("Number of block sections")
      await user.clear(input)
      await user.type(input, "1")
      await user.click(
        screen.getByRole("button", {
          name: year === 4 ? "Continue to review" : "Save and continue",
        }),
      )
    }
    await user.click(
      screen.getByRole("button", { name: "Generate subject list" }),
    )

    expect(
      await screen.findByText(
        "2 schedule assignments remaining will be included for Dean and Executive Director review.",
      ),
    ).toBeInTheDocument()
    await user.click(
      screen.getByRole("button", {
        name: "Submit for Dean and Executive Director Approval",
      }),
    )
    await user.click(screen.getByRole("button", { name: "Confirm submission" }))

    await waitFor(() =>
      expect(
        fetchMock.mock.calls.some(
          ([input, init]) =>
            url(input).includes("/section-plan/submit") &&
            init?.method === "POST",
        ),
      ).toBe(true),
    )
  })

  it("allows approval submission when only the professor is unassigned", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockAll({ missingProfessorOnly: true }))
    renderWorkspace()
    for (let year = 1; year <= 4; year++) {
      await chooseCurriculum(user, year)
      const input = await screen.findByLabelText("Number of block sections")
      await user.clear(input)
      await user.type(input, "1")
      await user.click(
        screen.getByRole("button", {
          name: year === 4 ? "Continue to review" : "Save and continue",
        }),
      )
    }
    await user.click(
      screen.getByRole("button", { name: "Generate subject list" }),
    )

    expect(
      screen.queryByText(/schedule assignment.*remaining/),
    ).not.toBeInTheDocument()
    expect(
      screen.getByRole("button", {
        name: "Submit for Dean and Executive Director Approval",
      }),
    ).toBeEnabled()
  })

  it("shows an approval submission API error beside the action", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(
      mockAll({ completeSchedules: true, failSubmit: true }),
    )
    renderWorkspace()
    for (let year = 1; year <= 4; year++) {
      await chooseCurriculum(user, year)
      const input = await screen.findByLabelText("Number of block sections")
      await user.clear(input)
      await user.type(input, "1")
      await user.click(
        screen.getByRole("button", {
          name: year === 4 ? "Continue to review" : "Save and continue",
        }),
      )
    }
    await user.click(
      screen.getByRole("button", { name: "Generate subject list" }),
    )
    await user.click(
      screen.getByRole("button", {
        name: "Submit for Dean and Executive Director Approval",
      }),
    )
    await user.click(screen.getByRole("button", { name: "Confirm submission" }))

    expect(
      await screen.findByText(
        "The approval submission could not be completed.",
      ),
    ).toBeInTheDocument()
  })

  it("shows reviewer remarks when a schedule is returned for correction", async () => {
    fetchMock.mockImplementation(
      mockAll({ returnedRemark: "Please correct the room conflict." }),
    )
    renderWorkspace()

    // Both the alert banner and the approval-status card now surface the
    // returned state (badge + title), so more than one match is expected.
    expect(
      await screen.findAllByText("Returned for correction"),
    ).not.toHaveLength(0)
    expect(
      screen.getAllByText(/Please correct the room conflict/),
    ).not.toHaveLength(0)
    expect(screen.getAllByText(/Dean Reyes/)).not.toHaveLength(0)
  })

  it("restores the submitted state and replaces the editable schedule with a clean 'waiting for Dean' view", async () => {
    fetchMock.mockImplementation(
      mockAll({
        completeSchedules: true,
        restoredPlans: true,
        proposal: {
          type: "schedule_proposal",
          id: 30,
          academic_term_id: 2,
          submitted_by: 4,
          submitted_by_name: "Chair",
          college: "ccs",
          college_label: "College of Computer Studies",
          academic_term_label: "2026-2027 · 1st",
          is_submitted: true,
          status: "draft",
          status_label: "Draft",
          decided_by: null,
          decided_by_name: null,
          decided_at: null,
          decision_reason: null,
          decision_history: [],
        },
      }),
    )
    renderWorkspace()

    expect(await screen.findByText("Submitted to Dean")).toBeInTheDocument()
    expect(screen.getByText(/Waiting for Dean review/)).toBeInTheDocument()
    // The editable generated-schedule card is gone entirely while pending
    // review — not shown-but-disabled.
    expect(
      screen.queryByText("Predictive schedule planning"),
    ).not.toBeInTheDocument()
    expect(screen.queryByText("IT101")).not.toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: "Add section" }),
    ).not.toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: "Assign schedule" }),
    ).not.toBeInTheDocument()
    expect(
      screen.queryByRole("button", {
        name: "Submit for Dean and Executive Director Approval",
      }),
    ).not.toBeInTheDocument()
    expect(
      screen.getByRole("link", { name: "Open Schedule" }),
    ).toHaveAttribute("href", "/portal/schedule")
  })

  it("shows the Dean and Executive Director approval history to the Program Chair", async () => {
    fetchMock.mockImplementation(
      mockAll({
        completeSchedules: true,
        restoredPlans: true,
        proposal: {
          type: "schedule_proposal",
          id: 30,
          academic_term_id: 2,
          submitted_by: 4,
          submitted_by_name: "Chair",
          college: "ccs",
          college_label: "College of Computer Studies",
          academic_term_label: "2026-2027 · 1st",
          is_submitted: true,
          status: "executive_approved",
          status_label: "Executive approved",
          decided_by: 7,
          decided_by_name: "Director Cruz",
          decided_at: "2026-08-02T06:00:00Z",
          decision_reason: null,
          decision_history: [
            {
              action: "dean_approve",
              action_label: "Approved by Dean",
              actor_name: "Dean Reyes",
              actor_role: "dean",
              decided_at: "2026-08-02T05:00:00Z",
              notes: null,
            },
            {
              action: "executive_approve",
              action_label: "Approved by Executive Director",
              actor_name: "Director Cruz",
              actor_role: "executive_director",
              decided_at: "2026-08-02T06:00:00Z",
              notes: null,
            },
          ],
        },
      }),
    )
    renderWorkspace()

    expect(
      await screen.findAllByText("Approved by Executive Director"),
    ).not.toHaveLength(0)
    expect(screen.getAllByText(/Dean Reyes/)).not.toHaveLength(0)
    expect(screen.getAllByText(/Director Cruz/)).not.toHaveLength(0)
  })

  it("shows a rejected schedule message inside the open modal", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockAll({ failScheduleSave: true }))
    renderWorkspace()
    for (let year = 1; year <= 4; year++) {
      await chooseCurriculum(user, year)
      const input = await screen.findByLabelText("Number of block sections")
      await user.clear(input)
      await user.type(input, "1")
      await user.click(
        screen.getByRole("button", {
          name: year === 4 ? "Continue to review" : "Save and continue",
        }),
      )
    }
    await user.click(
      screen.getByRole("button", { name: "Generate subject list" }),
    )
    await user.click(
      await screen.findByRole("button", { name: "View schedule & assign" }),
    )
    await user.click(
      await screen.findByRole("button", { name: "Assign schedule" }),
    )
    await user.click(screen.getByLabelText("Day"))
    await user.click(await screen.findByRole("option", { name: "T" }))
    fireEvent.change(screen.getByLabelText("Start time"), {
      target: { value: "13:17" },
    })
    fireEvent.change(screen.getByLabelText("End time"), {
      target: { value: "14:15" },
    })
    await user.click(screen.getByRole("button", { name: "Save schedule" }))

    const dialog = screen.getByRole("dialog")
    expect(dialog).toHaveTextContent(
      "This professor already has a class at this time.",
    )
  })

  it("offers an add-section action after subjects are released", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockAll())
    renderWorkspace()
    for (let year = 1; year <= 4; year++) {
      await chooseCurriculum(user, year)
      const input = await screen.findByLabelText("Number of block sections")
      await user.clear(input)
      await user.type(input, "1")
      await user.click(
        screen.getByRole("button", {
          name: year === 4 ? "Continue to review" : "Save and continue",
        }),
      )
    }
    await user.click(
      screen.getByRole("button", { name: "Generate subject list" }),
    )
    expect(
      await screen.findByRole("button", { name: "Add section" }),
    ).toBeEnabled()
    expect(screen.getByRole("button", { name: "Remove section" })).toBeEnabled()
  })

  it("shows the protected-block reason when a section removal would lower the plan too far", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockAll({ failProtectedBlockReduction: true }))
    renderWorkspace()
    for (let year = 1; year <= 4; year++) {
      await chooseCurriculum(user, year)
      const input = await screen.findByLabelText("Number of block sections")
      await user.clear(input)
      await user.type(input, "1")
      await user.click(
        screen.getByRole("button", {
          name: year === 4 ? "Continue to review" : "Save and continue",
        }),
      )
    }
    await user.click(
      screen.getByRole("button", { name: "Generate subject list" }),
    )
    await user.click(
      await screen.findByRole("button", { name: "Remove section" }),
    )

    expect(
      await screen.findByText(
        "Cannot reduce 1th-year sections below 2 while IT102 has assigned schedule or enrollment data.",
      ),
    ).toBeInTheDocument()
  })

  it("offers a year-specific subject generation action when a year is empty", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockAll())
    renderWorkspace()
    for (let year = 1; year <= 4; year++) {
      await chooseCurriculum(user, year)
      const input = await screen.findByLabelText("Number of block sections")
      await user.clear(input)
      await user.type(input, "1")
      await user.click(
        screen.getByRole("button", {
          name: year === 4 ? "Continue to review" : "Save and continue",
        }),
      )
    }
    await user.click(
      screen.getByRole("button", { name: "Generate subject list" }),
    )
    await user.click(await screen.findByRole("tab", { name: "3rd Year" }))
    expect(
      await screen.findByRole("button", {
        name: "Generate subjects for 3rd Year",
      }),
    ).toBeEnabled()
  })

  it("shows new and old curriculum effectivity before the chair chooses", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockAll())
    renderWorkspace()
    await screen.findByRole("heading", { name: "1st Year sections" })
    await user.click(screen.getByLabelText("Curriculum for 1st Year"))
    expect(
      await screen.findByRole("option", { name: /BSCS 2026.*New curriculum/ }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("option", { name: /BSCS 2024.*Old curriculum/ }),
    ).toBeInTheDocument()
  })

  it("displays the curriculum selector in the subjects view and allows switching curriculum", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockAll())
    renderWorkspace()
    for (let year = 1; year <= 4; year++) {
      await chooseCurriculum(user, year)
      const input = await screen.findByLabelText("Number of block sections")
      await user.clear(input)
      await user.type(input, "1")
      await user.click(
        screen.getByRole("button", {
          name: year === 4 ? "Continue to review" : "Save and continue",
        }),
      )
    }
    await user.click(
      screen.getByRole("button", { name: "Generate subject list" }),
    )
    expect(
      await screen.findByRole("combobox", { name: "Curriculum for 1st Year" }),
    ).toBeInTheDocument()
    await user.click(screen.getByRole("combobox", { name: "Curriculum for 1st Year" }))
    expect(
      await screen.findByRole("option", { name: /BSCS 2026/ }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("option", { name: /BSCS 2024/ }),
    ).toBeInTheDocument()
  })

  it("has no detectable accessibility violations once loaded", async () => {
    fetchMock.mockImplementation(mockAll())
    const { container } = renderWorkspace()
    await screen.findByRole("heading", { name: "1st Year sections" })
    await waitFor(() => expect(fetchMock).toHaveBeenCalled())
    expect(await axe(container)).toHaveNoViolations()
  })
})
