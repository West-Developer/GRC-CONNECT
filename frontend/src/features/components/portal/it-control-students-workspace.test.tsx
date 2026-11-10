import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { ItControlStudentsWorkspace } from "@/features/components/portal/it-control-students-workspace"
import { renderWithSession } from "@/tests/render-app"

const students = {
  data: [
    {
      type: "it-control-student-account",
      id: 12,
      user_id: 32,
      student_number: "2026-08-30001",
      name: "Avery Student",
      first_name: "Avery",
      last_name: "Student",
      suffix: null,
      email: "avery.student@grc.test",
      program_code: "BSCS",
      college: "ccs",
      year_level: 3,
      enrollment_category: "irregular",
      academic_standing: "good",
      status: "active",
      is_archived: false,
      current_term_enrollment_status: "pending_payment",
      password_hint: "password",
    },
  ],
  links: {
    first: "https://api.test/it-control/students?page=1",
    last: "https://api.test/it-control/students?page=1",
    prev: null,
    next: null,
  },
  meta: {
    current_page: 1,
    from: 1,
    last_page: 1,
    links: [
      {
        url: null,
        label: "&laquo; Previous",
        active: false,
      },
      {
        url: "https://api.test/it-control/students?page=1",
        label: "1",
        active: true,
      },
      { url: null, label: "Next &raquo;", active: false },
    ],
    path: "https://api.test/it-control/students",
    per_page: 20,
    to: 1,
    total: 1,
  },
} as const

function renderWorkspace(role: "it_admin" | "registrar_head" = "it_admin") {
  return renderWithSession(<ItControlStudentsWorkspace />, {
    session: {
      userId: "it-1",
      displayName: "IT Control",
      role,
      signedInAt: "2026-08-11T00:00:00Z",
    },
  })
}

describe("ItControlStudentsWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    fetchMock.mockImplementation(() =>
      Promise.resolve(new Response(JSON.stringify(students))),
    )
  })

  afterEach(() => vi.unstubAllGlobals())

  it("sends the applied student account filters in the fetch URL", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    await screen.findAllByText("Avery Student")
    await user.type(screen.getByLabelText("Search student accounts"), "Avery")
    await user.click(screen.getByRole("combobox", { name: "College" }))
    await user.click(
      screen.getByRole("option", { name: "College of Computer Studies" }),
    )
    await user.click(screen.getByRole("combobox", { name: "Year level" }))
    await user.click(screen.getByRole("option", { name: "3rd Year" }))
    await user.click(
      screen.getByRole("combobox", { name: "Enrollment category" }),
    )
    await user.click(screen.getByRole("option", { name: "Irregular" }))
    await user.click(
      screen.getByRole("button", { name: "Apply student filters" }),
    )

    await waitFor(() =>
      expect(fetchMock).toHaveBeenLastCalledWith(
        expect.stringContaining(
          "/api/v1/it-control/students?q=Avery&college=ccs&year_level=3&enrollment_category=irregular&page=1&per_page=20",
        ),
        expect.anything(),
      ),
    )
    expect(
      screen.getAllByRole("button", { name: "Copy email for Avery Student" }),
    ).not.toHaveLength(0)
  })

  it("renders the current-term enrollment status returned by the account API", async () => {
    renderWorkspace()

    expect(
      await screen.findByRole("columnheader", { name: "Current enrollment" }),
    ).toBeInTheDocument()
    expect(screen.getAllByText("Pending payment")).not.toHaveLength(0)
  })

  it("offers edit, archive and delete on an account, and restore instead on an archived one", async () => {
    renderWorkspace()

    // The table renders each row for a wide screen and for a phone, so a button appears more than once.
    await screen.findAllByText("Avery Student")
    expect(
      screen.getAllByRole("button", { name: "Edit Avery Student" }),
    ).not.toHaveLength(0)
    expect(
      screen.getAllByRole("button", { name: "Archive Avery Student" }),
    ).not.toHaveLength(0)
    expect(
      screen.getAllByRole("button", { name: "Delete Avery Student" }),
    ).not.toHaveLength(0)
    expect(
      screen.queryByRole("button", { name: "Restore Avery Student" }),
    ).not.toBeInTheDocument()
  })

  it("addresses the account behind the student, not the student record, when an action is taken", async () => {
    const user = userEvent.setup()
    renderWorkspace()
    await screen.findAllByText("Avery Student")
    fetchMock.mockImplementation(() =>
      Promise.resolve(
        new Response(
          '{"data":{"type":"it-control-account","id":32,"role":"student","name":"Avery Student","first_name":"Avery","last_name":"Student","suffix":null,"email":"avery.student@grc.test","status":"disabled","is_archived":true,"archived_at":"2026-10-16T08:00:00Z"}}',
        ),
      ),
    )

    await user.click(
      screen.getAllByRole("button", { name: "Archive Avery Student" })[0],
    )
    await user.click(
      within(await screen.findByRole("alertdialog")).getByRole("button", {
        name: "Archive account",
      }),
    )

    // The student record is 12; the account (user) is 32, and that is what the API is asked about.
    await waitFor(() =>
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringContaining("/api/v1/it-control/accounts/32/archive"),
        expect.objectContaining({ method: "POST" }),
      ),
    )
  })

  it("lists archived accounts under their own status filter and offers restore", async () => {
    const user = userEvent.setup()
    renderWorkspace()
    await screen.findAllByText("Avery Student")

    fetchMock.mockImplementation(() =>
      Promise.resolve(
        new Response(
          JSON.stringify({
            ...students,
            data: [
              {
                ...students.data[0],
                status: "disabled",
                is_archived: true,
              },
            ],
          }),
        ),
      ),
    )
    await user.click(screen.getByRole("combobox", { name: "Account status" }))
    await user.click(screen.getByRole("option", { name: "Archived" }))
    await user.click(
      screen.getByRole("button", { name: "Apply student filters" }),
    )

    await waitFor(() =>
      expect(fetchMock).toHaveBeenLastCalledWith(
        expect.stringContaining("status=archived"),
        expect.anything(),
      ),
    )
    expect(
      await screen.findAllByRole("button", { name: "Restore Avery Student" }),
    ).not.toHaveLength(0)
    expect(
      screen.getAllByText("Archived", { selector: "td" }),
    ).not.toHaveLength(0)
    expect(
      screen.queryByRole("button", { name: "Edit Avery Student" }),
    ).not.toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: "Archive Avery Student" }),
    ).not.toBeInTheDocument()
  })

  it("renders the role guard without fetching student accounts for an unauthorized role", () => {
    renderWorkspace("registrar_head")

    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("has no detectable accessibility violations once student accounts load", async () => {
    const { container } = renderWorkspace()

    await screen.findAllByText("Avery Student")
    expect(await axe(container)).toHaveNoViolations()
  })
})
