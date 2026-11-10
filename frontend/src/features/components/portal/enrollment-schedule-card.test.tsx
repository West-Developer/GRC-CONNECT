import { fireEvent, screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { toast } from "sonner"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { EnrollmentScheduleCard } from "@/features/components/portal/enrollment-schedule-card"
import { renderWithSession } from "@/tests/render-app"
import type { AcademicTerm } from "@/features/schemas/reference-data-schema"

vi.mock("sonner", () => ({ toast: { success: vi.fn() } }))

function url(input: RequestInfo | URL) {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

function registrarSession() {
  return {
    userId: "9",
    displayName: "Registrar Head",
    role: "registrar_head" as const,
    signedInAt: "2026-07-29T12:00:00Z",
  }
}

const draftTerm: AcademicTerm = {
  type: "academic-term",
  id: 5,
  school_year: "2028-2029",
  semester: "1st",
  starts_at: null,
  ends_at: null,
  enrollment_opens_at: "2028-07-01T00:00:00Z",
  enrollment_closes_at: "2028-07-15T00:00:00Z",
  add_drop_deadline_at: null,
  grading_deadline_at: null,
  status: "draft",
  status_label: "Draft",
}

const ongoingTerm: AcademicTerm = {
  ...draftTerm,
  status: "semester_ongoing",
  status_label: "Semester Ongoing",
}

function proposalFixture(college: string, status: string) {
  return {
    type: "schedule_proposal",
    id: college.length,
    academic_term_id: 5,
    college,
    college_label: college.toUpperCase(),
    academic_term_label: "2028-2029 · 1st",
    submitted_by: 1,
    submitted_by_name: "Chair",
    section_plan_id: null,
    is_submitted: true,
    is_returned: false,
    returned_by_role: null,
    status,
    status_label: status,
    decided_by: null,
    decided_by_name: null,
    decided_at: null,
    decision_reason: null,
    decision_history: [],
  }
}

const AUDIENCE_LABELS = [
  ["year_1", "1st Year"],
  ["year_2", "2nd Year"],
  ["year_3", "3rd Year"],
  ["year_4", "4th Year"],
  ["irregular", "Irregular Students"],
  ["late_enrollee", "Late Enrollees"],
] as const

function scheduleFixture(
  overrides: Partial<{ status: string; audiences: unknown[] }> = {},
) {
  return {
    data: {
      type: "enrollment_schedule",
      academic_term_id: 5,
      status: overrides.status ?? "draft",
      enrollment_opens_at: "2028-07-01T00:00:00Z",
      enrollment_closes_at: "2028-07-15T00:00:00Z",
      audiences:
        overrides.audiences ??
        AUDIENCE_LABELS.map(([audience, label]) => ({
          audience,
          label,
          opens_at: "2028-07-01T00:00:00Z",
          closes_at: "2028-07-15T00:00:00Z",
          is_open: true,
          reason: "open",
        })),
      viewer: null,
      add_drop: {
        is_open: false,
        reason: "enrollment_still_open",
        reason_message:
          "The add/drop window opens once enrollment closes for this term.",
        opens_at: "2028-07-15T00:00:00Z",
        closes_at: null,
      },
    },
  }
}

describe("EnrollmentScheduleCard", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("shows which colleges have published and explains enrollment will not open yet", async () => {
    fetchMock.mockImplementation((input) => {
      if (url(input).includes("/schedule-proposals"))
        return Promise.resolve(
          new Response(JSON.stringify({ data: [] }), { status: 200 }),
        )
      return Promise.resolve(new Response(JSON.stringify(scheduleFixture())))
    })

    renderWithSession(<EnrollmentScheduleCard currentTerm={draftTerm} />, {
      session: registrarSession(),
    })

    expect(await screen.findByText(/CCS not published/)).toBeInTheDocument()
    expect(
      screen.getByText(
        /Enrollment can be started once at least one college has published its schedule/,
      ),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: "Open enrollment" }),
    ).not.toBeInTheDocument()
  })

  it("does not auto-open enrollment when saving the schedule, saving schedule only saves dates", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) => {
      if (
        init?.method === "PATCH" &&
        url(input).includes("/academic-terms/5") &&
        !url(input).includes("enrollment-schedule")
      )
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: { ...draftTerm, status: "semester_ongoing" },
            }),
            { status: 200 },
          ),
        )
      if (url(input).includes("/schedule-proposals"))
        return Promise.resolve(
          new Response(
            JSON.stringify({ data: [proposalFixture("ccs", "published")] }),
            { status: 200 },
          ),
        )
      return Promise.resolve(new Response(JSON.stringify(scheduleFixture())))
    })

    renderWithSession(<EnrollmentScheduleCard currentTerm={draftTerm} />, {
      session: registrarSession(),
    })

    await waitFor(() =>
      expect(screen.getByText(/CCS published/)).toBeInTheDocument(),
    )
    await user.click(
      await screen.findByRole("button", { name: "Save enrollment schedule" }),
    )

    await waitFor(() =>
      expect(toast.success).toHaveBeenCalledWith("Enrollment schedule saved."),
    )
    expect(fetchMock).not.toHaveBeenCalledWith(
      expect.stringContaining("/academic-terms/5"),
      expect.objectContaining({
        method: "PATCH",
        body: JSON.stringify({ action: "open_enrollment" }),
      }),
    )
  })

  it("shows the term's current platform and saves the one the Registrar picks", async () => {
    const user = userEvent.setup()
    let savedBody: Record<string, unknown> | null = null
    fetchMock.mockImplementation((input, init) => {
      if (url(input).includes("/schedule-proposals"))
        return Promise.resolve(new Response(JSON.stringify({ data: [] })))
      if (
        init?.method === "PATCH" &&
        url(input).includes("enrollment-schedule")
      ) {
        savedBody =
          typeof init.body === "string"
            ? (JSON.parse(init.body) as Record<string, unknown>)
            : null
        return Promise.resolve(new Response(JSON.stringify(scheduleFixture())))
      }
      return Promise.resolve(new Response(JSON.stringify(scheduleFixture())))
    })

    renderWithSession(
      <EnrollmentScheduleCard
        currentTerm={{
          ...draftTerm,
          enrollment_platform: "online",
          enrollment_platform_label: "Online",
        }}
      />,
      { session: registrarSession() },
    )

    const platform = await screen.findByLabelText("Platform for this term")
    await waitFor(() => expect(platform).toHaveValue("online"))
    expect(
      screen.getByText(/printed on each student's Certificate of Registration/),
    ).toBeInTheDocument()

    await user.selectOptions(platform, "face_to_face")
    await user.click(
      screen.getByRole("button", { name: "Save enrollment schedule" }),
    )

    await waitFor(() =>
      expect(savedBody?.enrollment_platform).toBe("face_to_face"),
    )
  })

  it("lets the Registrar pick Both Face-to-Face and Online", async () => {
    const user = userEvent.setup()
    let savedBody: Record<string, unknown> | null = null
    fetchMock.mockImplementation((input, init) => {
      if (url(input).includes("/schedule-proposals"))
        return Promise.resolve(new Response(JSON.stringify({ data: [] })))
      if (
        init?.method === "PATCH" &&
        url(input).includes("enrollment-schedule")
      ) {
        savedBody =
          typeof init.body === "string"
            ? (JSON.parse(init.body) as Record<string, unknown>)
            : null
        return Promise.resolve(new Response(JSON.stringify(scheduleFixture())))
      }
      return Promise.resolve(new Response(JSON.stringify(scheduleFixture())))
    })

    renderWithSession(
      <EnrollmentScheduleCard
        currentTerm={{
          ...draftTerm,
          enrollment_platform: "online",
          enrollment_platform_label: "Online",
        }}
      />,
      { session: registrarSession() },
    )

    const platform = await screen.findByLabelText("Platform for this term")
    await waitFor(() => expect(platform).toHaveValue("online"))
    expect(
      screen.getByRole("option", { name: "Both (Face-to-Face and Online)" }),
    ).toBeInTheDocument()

    await user.selectOptions(platform, "both")
    await user.click(
      screen.getByRole("button", { name: "Save enrollment schedule" }),
    )

    await waitFor(() => expect(savedBody?.enrollment_platform).toBe("both"))
  })

  it("sends null to clear the platform when it is set back to Not set", async () => {
    const user = userEvent.setup()
    let savedBody: Record<string, unknown> | null = null
    fetchMock.mockImplementation((input, init) => {
      if (url(input).includes("/schedule-proposals"))
        return Promise.resolve(new Response(JSON.stringify({ data: [] })))
      if (
        init?.method === "PATCH" &&
        url(input).includes("enrollment-schedule")
      ) {
        savedBody =
          typeof init.body === "string"
            ? (JSON.parse(init.body) as Record<string, unknown>)
            : null
      }
      return Promise.resolve(new Response(JSON.stringify(scheduleFixture())))
    })

    renderWithSession(
      <EnrollmentScheduleCard
        currentTerm={{ ...draftTerm, enrollment_platform: "online" }}
      />,
      { session: registrarSession() },
    )

    const platform = await screen.findByLabelText("Platform for this term")
    await waitFor(() => expect(platform).toHaveValue("online"))
    await user.selectOptions(platform, "")
    await user.click(
      screen.getByRole("button", { name: "Save enrollment schedule" }),
    )

    await waitFor(() => expect(savedBody).not.toBeNull())
    expect(savedBody).toHaveProperty("enrollment_platform", null)
  })
  it("shows live per-audience status once the term is ongoing", async () => {
    fetchMock.mockImplementation((input) => {
      if (url(input).includes("/schedule-proposals"))
        return Promise.resolve(new Response(JSON.stringify({ data: [] })))
      return Promise.resolve(
        new Response(
          JSON.stringify(
            scheduleFixture({
              status: "semester_ongoing",
              audiences: [
                {
                  audience: "year_1",
                  label: "1st Year",
                  opens_at: null,
                  closes_at: null,
                  is_open: true,
                  reason: "open",
                },
                {
                  audience: "year_2",
                  label: "2nd Year",
                  opens_at: "2028-08-01T00:00:00Z",
                  closes_at: null,
                  is_open: false,
                  reason: "before_window",
                },
                {
                  audience: "year_3",
                  label: "3rd Year",
                  opens_at: null,
                  closes_at: null,
                  is_open: true,
                  reason: "open",
                },
                {
                  audience: "year_4",
                  label: "4th Year",
                  opens_at: null,
                  closes_at: null,
                  is_open: true,
                  reason: "open",
                },
                {
                  audience: "irregular",
                  label: "Irregular Students",
                  opens_at: "2028-09-01T00:00:00Z",
                  closes_at: null,
                  is_open: false,
                  reason: "before_window",
                },
                {
                  audience: "late_enrollee",
                  label: "Late Enrollees",
                  opens_at: null,
                  closes_at: null,
                  is_open: true,
                  reason: "open",
                },
              ],
            }),
          ),
        ),
      )
    })

    renderWithSession(<EnrollmentScheduleCard currentTerm={ongoingTerm} />, {
      session: registrarSession(),
    })

    expect((await screen.findAllByText("Open now")).length).toBe(4)
    expect((await screen.findAllByText("Opens later")).length).toBe(2)
  })

  it("saves the enrollment schedule with the term-wide and per-audience dates", async () => {
    const user = userEvent.setup()
    let savedBody: unknown = null
    fetchMock.mockImplementation((input, init) => {
      if (url(input).includes("/schedule-proposals"))
        return Promise.resolve(new Response(JSON.stringify({ data: [] })))
      if (
        init?.method === "PATCH" &&
        url(input).includes("enrollment-schedule")
      ) {
        savedBody = typeof init.body === "string" ? JSON.parse(init.body) : null
        return Promise.resolve(
          new Response(JSON.stringify(scheduleFixture()), { status: 200 }),
        )
      }
      return Promise.resolve(new Response(JSON.stringify(scheduleFixture())))
    })

    renderWithSession(<EnrollmentScheduleCard currentTerm={draftTerm} />, {
      session: registrarSession(),
    })

    await user.click(
      await screen.findByRole("button", { name: "Save enrollment schedule" }),
    )

    await waitFor(() => expect(savedBody).not.toBeNull())
    const body = savedBody as {
      enrollment_opens_at: string
      windows: { audience: string }[]
    }
    // Six, not five: `UpdateEnrollmentScheduleRequest` validates
    // `size:count(EnrollmentAudience::cases())`, so a five-item array 422s.
    expect(body.windows).toHaveLength(6)
    expect(body.windows.map((window) => window.audience)).toEqual([
      "year_1",
      "year_2",
      "year_3",
      "year_4",
      "irregular",
      "late_enrollee",
    ])
    expect(body.enrollment_opens_at).toBe("2028-07-01T00:00:00.000Z")
    expect(
      await screen.findByText("Enrollment schedule saved."),
    ).toBeInTheDocument()
    expect(toast.success).toHaveBeenCalledWith("Enrollment schedule saved.")
  })

  it("labels rows with ordinals, uses date-only inputs, and states the fixed 8am/11:59pm times", async () => {
    fetchMock.mockImplementation((input) => {
      if (url(input).includes("/schedule-proposals"))
        return Promise.resolve(new Response(JSON.stringify({ data: [] })))
      return Promise.resolve(new Response(JSON.stringify(scheduleFixture())))
    })

    renderWithSession(<EnrollmentScheduleCard currentTerm={draftTerm} />, {
      session: registrarSession(),
    })

    expect(await screen.findByText("1st Year")).toBeInTheDocument()
    expect(screen.getByText("2nd Year")).toBeInTheDocument()
    expect(screen.getByText("3rd Year")).toBeInTheDocument()
    expect(screen.getByText("4th Year")).toBeInTheDocument()
    expect(screen.getByText("Irregular Students")).toBeInTheDocument()
    expect(screen.getByText("Late Enrollees")).toBeInTheDocument()
    expect(screen.queryByText("Year 1")).not.toBeInTheDocument()

    expect(
      screen.getByText(/Enrollment always starts at 8:00 AM/i),
    ).toBeInTheDocument()

    expect(
      screen.getByLabelText("Term-wide enrollment start date"),
    ).toHaveAttribute("type", "date")
    expect(
      screen.getByLabelText("Term-wide enrollment deadline date"),
    ).toHaveAttribute("type", "date")
  })

  it("configures and saves the Add, Drop & Change Subject timeframe", async () => {
    const user = userEvent.setup()
    let savedBody: any = null
    fetchMock.mockImplementation((input, init) => {
      if (url(input).includes("/schedule-proposals"))
        return Promise.resolve(new Response(JSON.stringify({ data: [] })))
      if (
        init?.method === "PATCH" &&
        url(input).includes("enrollment-schedule")
      ) {
        savedBody = typeof init.body === "string" ? JSON.parse(init.body) : null
        return Promise.resolve(
          new Response(JSON.stringify(scheduleFixture()), { status: 200 }),
        )
      }
      return Promise.resolve(new Response(JSON.stringify(scheduleFixture())))
    })

    const { container } = renderWithSession(
      <EnrollmentScheduleCard currentTerm={draftTerm} />,
      {
        session: registrarSession(),
      },
    )

    expect(
      await screen.findByText("Add, Drop & Change Subject Schedule"),
    ).toBeInTheDocument()

    // Wait for the query to resolve and populate the form
    await screen.findByText("1st Year")

    const addDropOpens = container.querySelector(
      "#schedule-add_drop_opens_at",
    ) as HTMLInputElement
    const addDropCloses = container.querySelector(
      "#schedule-add_drop_closes_at",
    ) as HTMLInputElement

    fireEvent.change(addDropOpens, { target: { value: "2028-07-20" } })
    fireEvent.change(addDropCloses, { target: { value: "2028-07-25" } })

    await user.click(
      await screen.findByRole("button", { name: "Save enrollment schedule" }),
    )

    await waitFor(() => expect(savedBody).not.toBeNull())
    expect(savedBody.add_drop_opens_at).toBe("2028-07-20T00:00:00.000Z")
    expect(savedBody.add_drop_closes_at).toBe("2028-07-25T15:59:00.000Z")
  })

  it("starts enrollment when clicking the explicit Start enrollment button", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) => {
      if (
        init?.method === "PATCH" &&
        url(input).includes("/academic-terms/5") &&
        !url(input).includes("enrollment-schedule")
      ) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: { ...draftTerm, status: "semester_ongoing" },
            }),
            { status: 200 },
          ),
        )
      }
      if (url(input).includes("/schedule-proposals")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({ data: [proposalFixture("ccs", "published")] }),
            { status: 200 },
          ),
        )
      }
      return Promise.resolve(new Response(JSON.stringify(scheduleFixture())))
    })

    renderWithSession(<EnrollmentScheduleCard currentTerm={draftTerm} />, {
      session: registrarSession(),
    })

    const startBtn = await screen.findByRole("button", {
      name: "Start enrollment",
    })
    await user.click(startBtn)

    await waitFor(() =>
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringContaining("/academic-terms/5"),
        expect.objectContaining({
          method: "PATCH",
          body: JSON.stringify({ action: "open_enrollment" }),
        }),
      ),
    )
    expect(toast.success).toHaveBeenCalledWith(
      "Enrollment is now officially started and open for students.",
    )
  })

  it("refreshes the live enrollment status right after Start enrollment", async () => {
    const user = userEvent.setup()
    let scheduleReads = 0
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (init?.method === "PATCH" && !target.includes("enrollment-schedule")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: { ...draftTerm, status: "semester_ongoing" },
            }),
          ),
        )
      }
      if (target.includes("/schedule-proposals")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({ data: [proposalFixture("ccs", "published")] }),
          ),
        )
      }
      if (target.includes("/enrollment-windows")) scheduleReads += 1
      return Promise.resolve(new Response(JSON.stringify(scheduleFixture())))
    })

    renderWithSession(<EnrollmentScheduleCard currentTerm={draftTerm} />, {
      session: registrarSession(),
    })

    await user.click(
      await screen.findByRole("button", { name: "Start enrollment" }),
    )
    await waitFor(() =>
      expect(toast.success).toHaveBeenCalledWith(
        "Enrollment is now officially started and open for students.",
      ),
    )
    const readsAfterStart = scheduleReads

    // The grid that says "Enrollment not opened" comes from this query; it
    // must be refetched, not left cached for minutes.
    expect(readsAfterStart).toBeGreaterThanOrEqual(2)
  })

  it("does not offer Start enrollment as usable while the published schedules are still loading", async () => {
    fetchMock.mockImplementation((input) => {
      if (url(input).includes("/schedule-proposals"))
        return new Promise<Response>(() => undefined)
      return Promise.resolve(new Response(JSON.stringify(scheduleFixture())))
    })

    renderWithSession(<EnrollmentScheduleCard currentTerm={draftTerm} />, {
      session: registrarSession(),
    })

    expect(
      await screen.findByRole("button", { name: "Start enrollment" }),
    ).toBeDisabled()
  })

  it("asks for the term-wide dates instead of showing an API contract error when they are blank", async () => {
    const user = userEvent.setup()
    const fixture = scheduleFixture()
    fetchMock.mockImplementation((input, init) => {
      if (url(input).includes("/schedule-proposals"))
        return Promise.resolve(new Response(JSON.stringify({ data: [] })))
      if (init?.method === "PATCH")
        throw new Error("nothing may be sent while the dates are blank")
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: {
              ...fixture.data,
              enrollment_opens_at: null,
              enrollment_closes_at: null,
            },
          }),
        ),
      )
    })

    renderWithSession(<EnrollmentScheduleCard currentTerm={draftTerm} />, {
      session: registrarSession(),
    })

    await screen.findByText("1st Year")
    await user.click(
      screen.getByRole("button", { name: "Save enrollment schedule" }),
    )

    const alert = await screen.findByRole("alert")
    expect(alert).toHaveTextContent(
      /Enter the term-wide enrollment start and deadline dates/i,
    )
    expect(alert).not.toHaveTextContent(/contract/i)
  })

  it("names the audience rows that are missing dates", async () => {
    const user = userEvent.setup()
    const fixture = scheduleFixture()
    fetchMock.mockImplementation((input, init) => {
      if (url(input).includes("/schedule-proposals"))
        return Promise.resolve(new Response(JSON.stringify({ data: [] })))
      if (init?.method === "PATCH")
        throw new Error("nothing may be sent while a window is blank")
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: {
              ...fixture.data,
              audiences: (
                fixture.data.audiences as Record<string, unknown>[]
              ).map((audience, index) =>
                index === 0 || index === 4
                  ? { ...audience, opens_at: null, closes_at: null }
                  : audience,
              ),
            },
          }),
        ),
      )
    })

    renderWithSession(<EnrollmentScheduleCard currentTerm={draftTerm} />, {
      session: registrarSession(),
    })

    await screen.findByText("1st Year")
    await user.click(
      screen.getByRole("button", { name: "Save enrollment schedule" }),
    )

    const alert = await screen.findByRole("alert")
    expect(alert).toHaveTextContent("1st Year")
    expect(alert).toHaveTextContent("Irregular Students")
    expect(alert).not.toHaveTextContent("2nd Year")
  })

  it("shows each API validation message once even when many audience rows repeat it", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) => {
      if (url(input).includes("/schedule-proposals"))
        return Promise.resolve(new Response(JSON.stringify({ data: [] })))
      if (
        init?.method === "PATCH" &&
        url(input).includes("enrollment-schedule")
      ) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              error: {
                code: "VALIDATION_ERROR",
                message: "The given data was invalid.",
                errors: {
                  "windows.0.closes_at": [
                    "An enrollment window must close after it opens.",
                  ],
                  "windows.1.closes_at": [
                    "An enrollment window must close after it opens.",
                  ],
                  "windows.2.closes_at": [
                    "An enrollment window must close after it opens.",
                  ],
                },
                request_id: "req-1",
              },
            }),
            { status: 422 },
          ),
        )
      }
      return Promise.resolve(new Response(JSON.stringify(scheduleFixture())))
    })

    renderWithSession(<EnrollmentScheduleCard currentTerm={draftTerm} />, {
      session: registrarSession(),
    })

    await screen.findByText("1st Year")
    await user.click(
      screen.getByRole("button", { name: "Save enrollment schedule" }),
    )

    const alert = await screen.findByRole("alert")
    expect(alert.textContent?.match(/must close after it opens/g)).toHaveLength(
      1,
    )
  })
})
