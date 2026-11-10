import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { FacultyWorkforceWorkspace } from "@/features/components/portal/faculty-workforce-workspace"
import { renderWithSession } from "@/tests/render-app"

const facultyCcs = {
  data: [
    {
      type: "faculty_member",
      id: 12,
      name: "Prof. Reyes",
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

const facultyAllColleges = {
  data: [
    ...facultyCcs.data,
    {
      type: "faculty_member",
      id: 40,
      name: "Prof. Santos",
      college: "coe",
      status: "active",
      status_label: "Active",
      employment_type: "part_time",
      employment_type_label: "Part-time",
      planning_unit_reference: null,
      is_assignable: true,
    },
  ],
} as const

function requestUrl(input: RequestInfo | URL): string {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

const subjectsCcs = {
  data: [
    { type: "subject", id: 501, code: "IT101", title: "Intro to Computing", units: 3, status: "active", status_label: "Active", is_completion_only: false, college: "ccs" },
  ],
} as const

const pendingSpecialization = {
  type: "faculty-specialization",
  id: 77,
  professor_id: 12,
  subject_id: 501,
  proficiency: "primary",
  proficiency_label: "Primary",
  source: "declared",
  notes: null,
  status: "pending",
  status_label: "Pending",
  decided_at: null,
  decision_reason: null,
} as const

function mockFetch(
  fetchMock: ReturnType<typeof vi.fn<typeof fetch>>,
  facultyPayload: unknown,
  specializationsPayload: unknown = { data: [] },
) {
  fetchMock.mockImplementation((input, init) => {
    const url = requestUrl(input)
    if (url.includes("/faculty-members")) return Promise.resolve(new Response(JSON.stringify(facultyPayload)))
    if (url.includes("/subjects")) return Promise.resolve(new Response(JSON.stringify(subjectsCcs)))
    if (url.includes("/faculty-specializations") && (!init?.method || init.method === "GET"))
      return Promise.resolve(new Response(JSON.stringify(specializationsPayload)))
    if (url.includes("/faculty-specializations") && init?.method === "POST")
      return Promise.resolve(new Response(JSON.stringify({ data: { ...pendingSpecialization, status: "approved", status_label: "Approved", source: "program_chair_assigned" } }), { status: 201 }))
    if (/\/faculty-specializations\/\d+$/.exec(url) && init?.method === "PATCH")
      return Promise.resolve(new Response(JSON.stringify({ data: { ...pendingSpecialization, status: "approved", status_label: "Approved" } })))
    return Promise.resolve(new Response(JSON.stringify({ data: [] })))
  })
}

describe("FacultyWorkforceWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("lets a Program Chair search the roster, open a professor, and edit their workforce profile", async () => {
    mockFetch(fetchMock, facultyCcs)
    const user = userEvent.setup()
    renderWithSession(<FacultyWorkforceWorkspace />, {
      session: {
        userId: "chair-1",
        displayName: "Program Chair",
        role: "program_chair",
        college: "ccs",
        signedInAt: "2026-08-09T00:00:00Z",
      },
    })

    await user.type(
      await screen.findByLabelText("Search faculty by name"),
      "Reyes",
    )
    expect(await screen.findByText("Prof. Reyes")).toBeInTheDocument()

    await user.click(screen.getByText("Prof. Reyes"))
    const dialog = await screen.findByRole("dialog", { name: "Prof. Reyes" })
    expect(within(dialog).getByLabelText("Account status")).toBeInTheDocument()
    expect(
      within(dialog).queryByText("You have read-only access."),
    ).not.toBeInTheDocument()
  })

  it("gives Registrar Head a read-only view with a college filter and no edit access", async () => {
    mockFetch(fetchMock, facultyAllColleges)
    const user = userEvent.setup()
    renderWithSession(<FacultyWorkforceWorkspace />, {
      session: {
        userId: "registrar-1",
        displayName: "Registrar Head",
        role: "registrar_head",
        college: null,
        signedInAt: "2026-08-09T00:00:00Z",
      },
    })

    expect(await screen.findByText("Prof. Reyes")).toBeInTheDocument()
    expect(screen.getByText("Prof. Santos")).toBeInTheDocument()
    expect(screen.getByLabelText("College")).toBeInTheDocument()

    await user.click(screen.getByText("Prof. Santos"))
    const dialog = await screen.findByRole("dialog", { name: "Prof. Santos" })
    expect(
      within(dialog).queryByLabelText("Account status"),
    ).not.toBeInTheDocument()
    expect(
      within(dialog).getByText("You have read-only access."),
    ).toBeInTheDocument()
  })

  it("shows a Program Chair the specialization list and lets them approve a pending row", async () => {
    mockFetch(fetchMock, facultyCcs, { data: [pendingSpecialization] })
    const user = userEvent.setup()
    renderWithSession(<FacultyWorkforceWorkspace />, {
      session: {
        userId: "chair-1",
        displayName: "Program Chair",
        role: "program_chair",
        college: "ccs",
        signedInAt: "2026-08-09T00:00:00Z",
      },
    })

    await user.click(await screen.findByText("Prof. Reyes"))
    const dialog = await screen.findByRole("dialog", { name: "Prof. Reyes" })

    expect(await within(dialog).findByText("IT101 — Intro to Computing")).toBeInTheDocument()
    expect(within(dialog).getByText("Pending")).toBeInTheDocument()

    await user.click(within(dialog).getByRole("button", { name: "Approve" }))

    await waitFor(() => {
      const patchCall = fetchMock.mock.calls.find(
        ([, init]) => init?.method === "PATCH",
      )
      expect(patchCall).toBeDefined()
    })
  })

  it("requires a reason before rejecting a pending specialization", async () => {
    mockFetch(fetchMock, facultyCcs, { data: [pendingSpecialization] })
    const user = userEvent.setup()
    renderWithSession(<FacultyWorkforceWorkspace />, {
      session: {
        userId: "chair-1",
        displayName: "Program Chair",
        role: "program_chair",
        college: "ccs",
        signedInAt: "2026-08-09T00:00:00Z",
      },
    })

    await user.click(await screen.findByText("Prof. Reyes"))
    const dialog = await screen.findByRole("dialog", { name: "Prof. Reyes" })

    await user.click(within(dialog).getByRole("button", { name: "Reject" }))
    // The alert dialog portals to document.body as a sibling of the profile
    // dialog (not a descendant of it), so it is queried via `screen`, not
    // `within(dialog)` — matching the existing `faculty-availability-panel`
    // precedent for this same primitive.
    expect(screen.getByRole("button", { name: "Confirm rejection" })).toBeDisabled()

    await user.type(screen.getByLabelText("Reason for rejection"), "Needs more evidence.")
    expect(screen.getByRole("button", { name: "Confirm rejection" })).toBeEnabled()
  })

  it("does not show manage controls for a Registrar Head viewing the panel", async () => {
    mockFetch(fetchMock, facultyAllColleges, { data: [pendingSpecialization] })
    const user = userEvent.setup()
    renderWithSession(<FacultyWorkforceWorkspace />, {
      session: {
        userId: "registrar-1",
        displayName: "Registrar Head",
        role: "registrar_head",
        college: null,
        signedInAt: "2026-08-09T00:00:00Z",
      },
    })

    await user.click(await screen.findByText("Prof. Reyes"))
    const dialog = await screen.findByRole("dialog", { name: "Prof. Reyes" })

    expect(await within(dialog).findByText("IT101 — Intro to Computing")).toBeInTheDocument()
    expect(within(dialog).queryByRole("button", { name: "Approve" })).not.toBeInTheDocument()
    expect(within(dialog).queryByRole("button", { name: "Add subject" })).not.toBeInTheDocument()
  })
})
