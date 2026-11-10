import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { ItControlFacultyWorkspace } from "@/features/components/portal/it-control-faculty-workspace"
import { renderWithSession } from "@/tests/render-app"

const faculty = {
  data: [
    {
      type: "it-control-faculty-account",
      id: 14,
      name: "Avery Faculty",
      first_name: "Avery",
      last_name: "Faculty",
      suffix: null,
      email: "avery.faculty@grc.test",
      college: "ccs",
      employment_type: "full_time",
      status: "active",
      is_archived: false,
      availability_window_count: 2,
      subject_preference_count: 3,
      specialization_count: 1,
      password_hint: "password",
    },
  ],
  links: {
    first: "https://api.test/it-control/faculty?page=1",
    last: "https://api.test/it-control/faculty?page=1",
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
        url: "https://api.test/it-control/faculty?page=1",
        label: "1",
        active: true,
      },
      { url: null, label: "Next &raquo;", active: false },
    ],
    path: "https://api.test/it-control/faculty",
    per_page: 20,
    to: 1,
    total: 1,
  },
} as const

function renderWorkspace(role: "it_admin" | "registrar_head" = "it_admin") {
  return renderWithSession(<ItControlFacultyWorkspace />, {
    session: {
      userId: "it-1",
      displayName: "IT Control",
      role,
      signedInAt: "2026-08-11T00:00:00Z",
    },
  })
}

describe("ItControlFacultyWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    fetchMock.mockImplementation(() =>
      Promise.resolve(new Response(JSON.stringify(faculty))),
    )
  })

  afterEach(() => vi.unstubAllGlobals())

  it("sends the applied faculty account filters in the fetch URL", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    await screen.findAllByText("Avery Faculty")
    await user.type(screen.getByLabelText("Search faculty accounts"), "Avery")
    await user.click(screen.getByRole("combobox", { name: "College" }))
    await user.click(
      screen.getByRole("option", { name: "College of Computer Studies" }),
    )
    await user.click(screen.getByRole("combobox", { name: "Employment type" }))
    await user.click(screen.getByRole("option", { name: "Full-time" }))
    await user.click(screen.getByRole("combobox", { name: "Account status" }))
    await user.click(screen.getByRole("option", { name: "Active" }))
    await user.click(
      screen.getByRole("button", { name: "Apply faculty filters" }),
    )

    await waitFor(() =>
      expect(fetchMock).toHaveBeenLastCalledWith(
        expect.stringContaining(
          "/api/v1/it-control/faculty?q=Avery&college=ccs&employment_type=full_time&status=active&page=1&per_page=20",
        ),
        expect.anything(),
      ),
    )
    expect(
      screen.getAllByRole("button", { name: "Copy email for Avery Faculty" }),
    ).not.toHaveLength(0)
  })

  it("offers the account actions and addresses the faculty account itself", async () => {
    const user = userEvent.setup()
    renderWorkspace()
    await screen.findAllByText("Avery Faculty")
    fetchMock.mockImplementation(() =>
      Promise.resolve(
        new Response(
          '{"data":{"type":"it-control-account","id":14,"role":"faculty","name":"Avery Faculty","first_name":"Avery","last_name":"Faculty","suffix":null,"email":"avery.faculty@grc.test","status":"disabled","is_archived":true,"archived_at":"2026-10-16T08:00:00Z"}}',
        ),
      ),
    )

    expect(
      screen.getAllByRole("button", { name: "Edit Avery Faculty" }),
    ).not.toHaveLength(0)
    await user.click(
      screen.getAllByRole("button", { name: "Archive Avery Faculty" })[0],
    )
    await user.click(
      within(await screen.findByRole("alertdialog")).getByRole("button", {
        name: "Archive account",
      }),
    )

    await waitFor(() =>
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringContaining("/api/v1/it-control/accounts/14/archive"),
        expect.objectContaining({ method: "POST" }),
      ),
    )
  })

  it("renders the role guard without fetching faculty accounts for an unauthorized role", () => {
    renderWorkspace("registrar_head")

    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("has no detectable accessibility violations once faculty accounts load", async () => {
    const { container } = renderWorkspace()

    await screen.findAllByText("Avery Faculty")
    expect(await axe(container)).toHaveNoViolations()
  })
})
