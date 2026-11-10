import { screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { FacultyInputWorkspace } from "@/features/components/portal/faculty-input-workspace"
import { renderWithSession } from "@/tests/render-app"

const facultySession = {
  userId: "5",
  displayName: "Faculty",
  role: "faculty" as const,
  signedInAt: "2026-07-29T12:00:00Z",
}

const catalog = {
  data: [
    {
      curriculum_id: 11,
      program_id: 2,
      program_code: "BSIT",
      program_name: "Information Technology",
      curriculum_name: "2024–2029",
      effective_school_year: "2024-2029",
      version_label: "new",
      semesters: [
        {
          semester: "1st",
          subjects: [
            {
              id: 501,
              code: "LEAD 1",
              title: "Leadership Seminar 1",
              units: 1.5,
            },
            {
              id: 502,
              code: "IT 101",
              title: "Introduction to Computing",
              units: 3,
            },
          ],
        },
        { semester: "2nd", subjects: [] },
      ],
    },
  ],
} as const

const availability = {
  type: "faculty_availability",
  id: 4,
  professor_id: 5,
  day_of_week: 1,
  starts_at_time: "08:00:00",
  ends_at_time: "10:00:00",
} as const

const preference = {
  type: "faculty_curriculum_subject_preference",
  id: 6,
  professor_id: 5,
  curriculum_id: 11,
  semester: "1st",
  subject_id: 501,
  rank: 1,
  origin: "workbook_seeded",
} as const

const specialization = {
  type: "faculty-specialization",
  id: 9,
  professor_id: 5,
  subject_id: 501,
  proficiency: "primary",
  proficiency_label: "Primary",
  source: "declared",
  status: "approved",
  status_label: "Approved",
  decided_at: null,
  decision_reason: null,
  notes: null,
} as const

function requestUrl(input: RequestInfo | URL): string {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : input.url
}

function stubData(fetchMock: ReturnType<typeof vi.fn<typeof fetch>>) {
  fetchMock.mockImplementation((input, init) => {
    const url = requestUrl(input)

    if (url.endsWith("/faculty-preference-catalog"))
      return Promise.resolve(new Response(JSON.stringify(catalog)))
    if (
      url.endsWith("/faculty-availabilities") &&
      (!init?.method || init.method === "GET")
    )
      return Promise.resolve(
        new Response(JSON.stringify({ data: [availability] })),
      )
    if (url.endsWith("/faculty-specializations"))
      return Promise.resolve(
        new Response(JSON.stringify({ data: [specialization] })),
      )
    if (
      url.endsWith("/faculty-curriculum-subject-preferences") &&
      (!init?.method || init.method === "GET")
    )
      return Promise.resolve(
        new Response(JSON.stringify({ data: [preference] })),
      )
    if (url.endsWith("/faculty-teaching-history"))
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))

    return Promise.resolve(new Response(null, { status: 204 }))
  })
}

describe("FacultyInputWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("keeps availability and subject preferences on separate tabs", async () => {
    const user = userEvent.setup()
    stubData(fetchMock)
    renderWithSession(<FacultyInputWorkspace />, { session: facultySession })

    expect(
      await screen.findByRole("tab", { name: "Availability window" }),
    ).toBeInTheDocument()
    await user.click(screen.getByRole("tab", { name: "Subject preferences" }))

    expect(
      await screen.findByRole("table", { name: /subject preferences/i }),
    ).toBeInTheDocument()
    expect(screen.queryByLabelText(/^Day$/)).not.toBeInTheDocument()
  })

  it("shows each saved proficiency inside the single subject preferences table", async () => {
    const user = userEvent.setup()
    stubData(fetchMock)
    renderWithSession(<FacultyInputWorkspace />, { session: facultySession })

    await user.click(
      await screen.findByRole("tab", { name: "Subject preferences" }),
    )

    expect(
      await screen.findByRole("table", {
        name: "Saved curriculum subject preferences",
      }),
    ).toBeInTheDocument()
    expect(screen.queryByText("Declared specializations")).not.toBeInTheDocument()
    expect(
      await screen.findByRole("combobox", { name: /^Proficiency for / }),
    ).toHaveTextContent("Primary")
    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining("/api/v1/faculty-specializations"),
      expect.objectContaining({ method: "GET" }),
    )
  })

  it("does not fetch or render faculty input for an unauthorized role", () => {
    renderWithSession(<FacultyInputWorkspace />, {
      session: { ...facultySession, role: "executive_director" },
    })

    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("has no detectable accessibility violations once the tabs load", async () => {
    stubData(fetchMock)
    const { container } = renderWithSession(<FacultyInputWorkspace />, {
      session: facultySession,
    })

    await screen.findByRole("tab", { name: "Availability window" })
    expect(await axe(container)).toHaveNoViolations()
  })
})
