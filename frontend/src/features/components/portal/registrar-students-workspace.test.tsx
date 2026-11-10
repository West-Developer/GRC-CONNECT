import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { RegistrarStudentsWorkspace } from "@/features/components/portal/registrar-students-workspace"
import { renderWithSession } from "@/tests/render-app"

const links = {
  first: "http://localhost/api/v1/students?page=1",
  last: "http://localhost/api/v1/students?page=1",
  prev: null,
  next: null,
}

function student(overrides: Record<string, unknown>) {
  return {
    type: "registrar_student",
    id: 1,
    student_number: "HIST-2016-10806",
    name: "Ignacio, Raymond Q.",
    program_code: "BSED-ENG",
    year_level: 4,
    enrollment_category: "regular",
    academic_standing: "good",
    academic_standing_label: "Good standing",
    ...overrides,
  }
}

const rows = [
  student({}),
  student({
    id: 2,
    student_number: "2026-10-00002",
    name: "Santos, Maria",
    year_level: 1,
    enrollment_category: "irregular",
    academic_standing: "probation",
    academic_standing_label: "Probation",
  }),
  student({
    id: 3,
    student_number: "2026-10-00003",
    name: "Reyes, Pedro",
    enrollment_category: null,
  }),
]

const profile = {
  type: "student_profile",
  id: 1,
  user_id: 40,
  student_number: "HIST-2016-10806",
  name: "Ignacio, Raymond Q.",
  first_name: "Raymond",
  middle_initial: "Q",
  last_name: "Ignacio",
  suffix: null,
  email: "raymond.ignacio@grc.com",
  address: null,
  program_id: 1,
  program_code: "BSED-ENG",
  program_name: "Bachelor of Secondary Education major in English",
  curriculum_id: 1,
  entry_year: 2016,
  curriculum_name: "BSED-ENG Curriculum",
  curriculum_effective_school_year: "2016-2017",
  year_level: 4,
  enrollment_category: "regular",
  student_type: "freshman",
  student_type_label: "Freshman",
  admission_status: "admitted",
  admission_status_label: "Admitted",
  academic_standing: "good",
  academic_standing_label: "Good standing",
  financial_status: null,
  financial_status_label: null,
  requirements_verified_at: null,
  academic_setup_editable: false,
  account_setup_status: "active",
  invitation_delivery_status: "sent",
}

function urlOf(input: RequestInfo | URL): string {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : input.url
}

function session(role: "registrar_staff" | "registrar_head" | "student") {
  return {
    session: {
      userId: "1",
      displayName: "Someone",
      role,
      signedInAt: "2026-10-04T00:00:00Z",
    },
  }
}

describe("RegistrarStudentsWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    fetchMock.mockImplementation((input) =>
      Promise.resolve(
        new Response(
          JSON.stringify(
            urlOf(input).includes("registrar-profile")
              ? { data: profile }
              : {
                  data: rows,
                  links,
                  meta: {
                    current_page: 1,
                    last_page: 1,
                    per_page: 20,
                    total: 3,
                  },
                },
          ),
        ),
      ),
    )
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    fetchMock.mockReset()
  })

  it.each(["registrar_staff", "registrar_head"] as const)(
    "shows %s the students with their program, category and standing",
    async (role) => {
      renderWithSession(<RegistrarStudentsWorkspace />, session(role))

      const table = await screen.findByRole("table", { name: "Students" })
      const first = within(table)
        .getByText(/Ignacio, Raymond Q\./)
        .closest("tr")!
      expect(within(first).getByText("HIST-2016-10806")).toBeInTheDocument()
      expect(within(first).getByText("BSED-ENG · 4th Year")).toBeInTheDocument()
      expect(within(first).getByText("Regular")).toBeInTheDocument()
      expect(within(first).getByText("Good standing")).toBeInTheDocument()
      const second = within(table)
        .getByText(/Santos, Maria/)
        .closest("tr")!
      expect(within(second).getByText("Irregular")).toBeInTheDocument()
      expect(within(second).getByText("Probation")).toBeInTheDocument()
      const third = within(table)
        .getByText(/Reyes, Pedro/)
        .closest("tr")!
      expect(within(third).getByText("—")).toBeInTheDocument()
    },
  )

  it("calls it Students and never offers the freshmen filters", async () => {
    renderWithSession(<RegistrarStudentsWorkspace />, session("registrar_head"))

    await screen.findByRole("table", { name: "Students" })

    expect(
      screen.getByRole("heading", { level: 1, name: "Students" }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: /awaiting|all freshmen/i }),
    ).not.toBeInTheDocument()
  })

  it("opens the student's details when the Registrar Head selects the name", async () => {
    const user = userEvent.setup()
    renderWithSession(<RegistrarStudentsWorkspace />, session("registrar_head"))
    const table = await screen.findByRole("table", { name: "Students" })

    await user.click(
      within(table).getByRole("button", {
        name: /Ignacio, Raymond Q\..*open student information/,
      }),
    )

    expect(
      await screen.findByRole("dialog", { name: "Ignacio, Raymond Q." }),
    ).toBeInTheDocument()
    await waitFor(() =>
      expect(
        fetchMock.mock.calls.some(([input]) =>
          urlOf(input).includes("/api/v1/students/1/registrar-profile"),
        ),
      ).toBe(true),
    )
  })

  it("shows Registrar Staff the names as plain text, since only the Head may open a profile", async () => {
    renderWithSession(
      <RegistrarStudentsWorkspace />,
      session("registrar_staff"),
    )
    const table = await screen.findByRole("table", { name: "Students" })

    expect(within(table).getByText("Ignacio, Raymond Q.")).toBeInTheDocument()
    expect(
      within(table).queryByRole("button", { name: /open student information/ }),
    ).not.toBeInTheDocument()
  })

  it("searches by name or student number", async () => {
    const user = userEvent.setup()
    renderWithSession(
      <RegistrarStudentsWorkspace />,
      session("registrar_staff"),
    )
    await screen.findByRole("table", { name: "Students" })

    await user.type(screen.getByLabelText("Search students"), "Maria")

    await waitFor(() =>
      expect(
        fetchMock.mock.calls.some(([input]) =>
          urlOf(input).includes("search=Maria"),
        ),
      ).toBe(true),
    )
  })

  it("is not available to a student", () => {
    renderWithSession(<RegistrarStudentsWorkspace />, session("student"))

    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("has no detectable accessibility violations", async () => {
    const { container } = renderWithSession(
      <RegistrarStudentsWorkspace />,
      session("registrar_head"),
    )
    await screen.findByRole("table", { name: "Students" })

    expect(await axe(container)).toHaveNoViolations()
  })
})
