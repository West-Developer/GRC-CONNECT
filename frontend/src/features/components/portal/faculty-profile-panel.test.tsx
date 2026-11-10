import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { FacultyProfilePanel } from "@/features/components/portal/faculty-profile-panel"
import { renderWithSession } from "@/tests/render-app"

function section(
  id: number,
  code: string,
  grades: { draft: number; submitted: number; locked: number },
) {
  return {
    section_id: id,
    section_code: "A",
    subject_code: code,
    subject_title: `Title ${code}`,
    units: 3,
    schedule_days: "MON",
    starts_at_time: "08:00:00",
    ends_at_time: "10:00:00",
    room: "R101",
    modality: "f2f",
    status: "published",
    enrolled_count: 12,
    capacity: 40,
    grades: {
      ...grades,
      total: grades.draft + grades.submitted + grades.locked,
    },
  }
}

const professor = {
  id: 12,
  name: "Prof. Reyes",
  college: "ccs",
  status: "active",
  employment_type: "full_time",
  employment_type_label: "Full-time",
  masters_degree: "MIT",
}

const currentTerm = {
  academic_term_id: 2,
  label: "2026-2027 · 1st",
  total_units: 6,
  sections: [
    section(1, "IT101", { draft: 1, submitted: 1, locked: 2 }),
    section(2, "IT102", { draft: 0, submitted: 0, locked: 3 }),
  ],
}

const profile = {
  data: {
    professor,
    terms: [
      {
        academic_term_id: 2,
        label: "2026-2027 · 1st",
        sections_count: 2,
        total_units: 6,
      },
      {
        academic_term_id: 1,
        label: "2025-2026 · 2nd",
        sections_count: 1,
        total_units: 3,
      },
    ],
    selected_term: currentTerm,
  },
}

const olderTerm = {
  data: {
    ...profile.data,
    selected_term: {
      academic_term_id: 1,
      label: "2025-2026 · 2nd",
      total_units: 3,
      sections: [section(9, "OLD101", { draft: 0, submitted: 0, locked: 0 })],
    },
  },
}

function requestUrl(input: RequestInfo | URL): string {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

const registrarHead = {
  userId: "rh-1",
  displayName: "Registrar Head",
  role: "registrar_head" as const,
  signedInAt: "2026-09-26T00:00:00Z",
}

describe("FacultyProfilePanel", () => {
  const fetchMock = vi.fn<typeof fetch>()
  let body: unknown = profile

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    body = profile
    fetchMock.mockImplementation((input) => {
      const url = requestUrl(input)
      if (url.includes("academic_term_id=1")) {
        return Promise.resolve(new Response(JSON.stringify(olderTerm)))
      }
      return Promise.resolve(new Response(JSON.stringify(body)))
    })
  })
  afterEach(() => vi.unstubAllGlobals())

  it("shows employment information, the term's units, and each section's grade submission", async () => {
    const user = userEvent.setup()
    renderWithSession(<FacultyProfilePanel professorId={12} />, {
      session: registrarHead,
    })

    expect(await screen.findByText("Full-time")).toBeInTheDocument()
    expect(screen.getByText("CCS")).toBeInTheDocument()
    expect(screen.getByText("MIT")).toBeInTheDocument()
    expect(screen.getByText(/units in 2 sections/)).toBeInTheDocument()

    await user.click(screen.getByRole("tab", { name: "Sections and grades" }))
    const table = await screen.findByRole("table", {
      name: "Sections and grade submission",
    })
    const first = within(table).getByText("IT101").closest("tr")!
    expect(within(first).getByText("Draft 1")).toBeInTheDocument()
    expect(within(first).getByText("Locked 2")).toBeInTheDocument()
    expect(within(first).getByText("1 still draft")).toBeInTheDocument()
    expect(within(table).getByText("All locked")).toBeInTheDocument()
    expect(within(first).getByText("12 of 40")).toBeInTheDocument()
  })

  it("shows the weekly timetable the way the professor sees it", async () => {
    renderWithSession(<FacultyProfilePanel professorId={12} />, {
      session: registrarHead,
    })

    await screen.findByText("Full-time")
    expect(
      screen.getByRole("heading", { name: "Weekly timetable" }),
    ).toBeInTheDocument()
    expect(screen.getAllByText(/IT101/).length).toBeGreaterThan(0)
  })

  it("loads another term when it is picked", async () => {
    const user = userEvent.setup()
    renderWithSession(<FacultyProfilePanel professorId={12} />, {
      session: registrarHead,
    })

    await user.selectOptions(await screen.findByLabelText("Term"), "1")

    await waitFor(() =>
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringContaining(
          "/faculty-members/12/profile?academic_term_id=1",
        ),
        expect.anything(),
      ),
    )
    expect(await screen.findByText(/units in 1 section/)).toBeInTheDocument()
  })

  it("says so when the professor has no sections", async () => {
    body = { data: { professor, terms: [], selected_term: null } }
    renderWithSession(<FacultyProfilePanel professorId={12} />, {
      session: registrarHead,
    })

    expect(
      await screen.findByText(
        "This professor has not been assigned any section yet.",
      ),
    ).toBeInTheDocument()
  })

  it("shows an error state instead of a broken profile", async () => {
    fetchMock.mockImplementation(() =>
      Promise.resolve(
        new Response(
          JSON.stringify({
            error: { code: "FORBIDDEN", message: "No.", request_id: "r" },
          }),
          { status: 403 },
        ),
      ),
    )
    renderWithSession(<FacultyProfilePanel professorId={12} />, {
      session: registrarHead,
    })

    expect(await screen.findByRole("alert")).toBeInTheDocument()
  })

  it("makes no request for a role other than the Registrar Head", () => {
    renderWithSession(<FacultyProfilePanel professorId={12} />, {
      session: { ...registrarHead, role: "program_chair" },
    })

    expect(fetchMock).not.toHaveBeenCalled()
  })
})
