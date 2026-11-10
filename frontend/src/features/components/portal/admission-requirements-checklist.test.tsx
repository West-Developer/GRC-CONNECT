import { screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { AdmissionRequirementsChecklist } from "@/features/components/portal/admission-requirements-checklist"
import { StudentAdmissionWorkspace } from "@/features/components/portal/student-admission-workspace"
import { renderWithSession } from "@/tests/render-app"

const studentSession = {
  userId: "1",
  displayName: "Maria Santos",
  role: "student" as const,
  signedInAt: "2026-09-26T12:00:00Z",
}

const staffSession = {
  userId: "7",
  displayName: "Admission Staff",
  role: "admission_staff" as const,
  signedInAt: "2026-09-26T12:00:00Z",
}

function checklist(submitted: number[] = []) {
  const item = (id: number, name: string, isSystem = true) => ({
    requirement_type_id: id,
    name,
    is_system: isSystem,
    is_submitted: submitted.includes(id),
    submitted_at: submitted.includes(id) ? "2026-09-20T02:00:00Z" : null,
  })

  return {
    type: "admission_requirements",
    student: {
      student_profile_id: 4,
      student_number: "2026-0001",
      name: "Maria Santos",
      student_type: "freshman",
      student_type_label: "Freshman",
      admission_status: "admitted",
    },
    categories: [
      {
        category: "freshman",
        label: "Freshman requirements",
        items: [item(1, "Form 137"), item(2, "Form 138")],
      },
      {
        category: "additional",
        label: "Additional requirements",
        items: [item(11, "Original Birth Certificate (PSA)")],
      },
    ],
    summary: {
      required_count: 3,
      submitted_count: submitted.length,
      missing_count: 3 - submitted.length,
      complete: submitted.length === 3,
    },
  }
}

function url(input: RequestInfo | URL) {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : input.url
}

describe("AdmissionRequirementsChecklist", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => {
    vi.unstubAllGlobals()
    fetchMock.mockReset()
  })

  it("shows a student their own list read-only, submitted and missing", async () => {
    fetchMock.mockImplementation((input) => {
      const target = url(input)
      if (target.includes("/api/v1/me/admission-requirements")) {
        return Promise.resolve(
          new Response(JSON.stringify({ data: checklist([1]) })),
        )
      }
      return Promise.resolve(new Response(JSON.stringify({ data: {} })))
    })

    renderWithSession(<AdmissionRequirementsChecklist studentId={null} />, {
      session: studentSession,
    })

    expect(await screen.findByText("1 of 3 submitted")).toBeInTheDocument()
    expect(screen.getByText("2 missing")).toBeInTheDocument()
    expect(screen.getByText("Form 137")).toBeInTheDocument()
    expect(screen.getByText(/Submitted /)).toBeInTheDocument()
    expect(screen.getAllByText("Not yet submitted")).toHaveLength(2)
    // Read-only: nothing to tick, nothing to add.
    expect(screen.queryByRole("checkbox")).not.toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: "Add requirement" }),
    ).not.toBeInTheDocument()
  })

  it("lets Admission Staff tick a requirement and sends the new state", async () => {
    let putBody: unknown = null
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (
        target.includes("/student-profiles/4/admission-requirements/1") &&
        init?.method === "PUT"
      ) {
        putBody = JSON.parse(init.body as string)
        return Promise.resolve(
          new Response(JSON.stringify({ data: checklist([1]) })),
        )
      }
      if (target.includes("/student-profiles/4/admission-requirements")) {
        return Promise.resolve(
          new Response(JSON.stringify({ data: checklist() })),
        )
      }
      return Promise.resolve(new Response(JSON.stringify({ data: {} })))
    })

    const user = userEvent.setup()
    renderWithSession(
      <AdmissionRequirementsChecklist studentId={4} editable />,
      { session: staffSession },
    )

    await user.click(await screen.findByRole("checkbox", { name: "Form 137" }))

    expect(await screen.findByText("1 of 3 submitted")).toBeInTheDocument()
    expect(putBody).toEqual({ is_submitted: true })
    expect(screen.getByRole("checkbox", { name: "Form 137" })).toBeChecked()
  })

  it("adds a requirement to the shared list", async () => {
    let postBody: unknown = null
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (
        target.includes("/api/v1/admission-requirement-types") &&
        init?.method === "POST"
      ) {
        postBody = JSON.parse(init.body as string)
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                type: "admission_requirement_type",
                id: 30,
                category: "additional",
                name: "Barangay Clearance",
                is_system: false,
              },
            }),
            { status: 201 },
          ),
        )
      }
      if (target.includes("/student-profiles/4/admission-requirements")) {
        return Promise.resolve(
          new Response(JSON.stringify({ data: checklist() })),
        )
      }
      return Promise.resolve(new Response(JSON.stringify({ data: {} })))
    })

    const user = userEvent.setup()
    renderWithSession(
      <AdmissionRequirementsChecklist studentId={4} editable />,
      { session: staffSession },
    )

    await screen.findByText("0 of 3 submitted")
    await user.click(screen.getByRole("button", { name: "Add a requirement" }))
    const form = screen.getByRole("form", { name: "Add a requirement" })
    await user.type(
      within(form).getByLabelText("Requirement"),
      "Barangay Clearance",
    )
    await user.click(
      within(form).getByRole("button", { name: "Add requirement" }),
    )

    await vi.waitFor(() =>
      expect(postBody).toEqual({
        name: "Barangay Clearance",
        category: "additional",
      }),
    )
  })

  it("asks for at least two characters before adding", async () => {
    fetchMock.mockImplementation(() =>
      Promise.resolve(new Response(JSON.stringify({ data: checklist() }))),
    )

    const user = userEvent.setup()
    renderWithSession(
      <AdmissionRequirementsChecklist studentId={4} editable />,
      { session: staffSession },
    )

    await screen.findByText("0 of 3 submitted")
    await user.click(screen.getByRole("button", { name: "Add a requirement" }))
    const form = screen.getByRole("form", { name: "Add a requirement" })
    await user.type(within(form).getByLabelText("Requirement"), "x")
    await user.click(
      within(form).getByRole("button", { name: "Add requirement" }),
    )

    expect(
      await screen.findByText("Enter at least 2 characters."),
    ).toBeInTheDocument()
  })

  it("shows an error state when the checklist cannot be loaded", async () => {
    fetchMock.mockImplementation(() =>
      Promise.resolve(
        new Response(JSON.stringify({ message: "Forbidden" }), {
          status: 403,
        }),
      ),
    )

    renderWithSession(<AdmissionRequirementsChecklist studentId={null} />, {
      session: studentSession,
    })

    expect(await screen.findByRole("alert")).toBeInTheDocument()
  })
})

describe("StudentAdmissionWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => {
    vi.unstubAllGlobals()
    fetchMock.mockReset()
  })

  it("is not available to other roles", () => {
    renderWithSession(<StudentAdmissionWorkspace />, {
      session: staffSession,
    })

    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
  })

  it("shows the requirements checklist to a student", async () => {
    fetchMock.mockImplementation((input) => {
      if (url(input).includes("/api/v1/me/admission-requirements")) {
        return Promise.resolve(
          new Response(JSON.stringify({ data: checklist([1, 2, 11]) })),
        )
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })

    renderWithSession(<StudentAdmissionWorkspace />, {
      session: studentSession,
    })

    expect(await screen.findByText("3 of 3 submitted")).toBeInTheDocument()
    expect(
      screen.getByRole("heading", { level: 1, name: "Admission" }),
    ).toBeInTheDocument()
  })
})
