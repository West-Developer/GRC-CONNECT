import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { PrerequisiteWaiverSection } from "@/features/components/portal/prerequisite-waiver-section"
import { renderWithSession } from "@/tests/render-app"

const headSession = {
  userId: "5",
  displayName: "Registrar Head",
  role: "registrar_head",
  signedInAt: "2026-07-29T12:00:00Z",
} as const

const activeWaiver = {
  type: "subject_waiver",
  id: 8,
  student_id: 4,
  subject_id: 20,
  subject_code: "CS101",
  subject_title: "Intro to Computing",
  academic_term_id: 2,
  reason: "Passed the bridging course.",
  is_active: true,
  granted_at: "2026-09-26T01:00:00Z",
  revoked_at: null,
} as const

function overview(
  waivers: readonly unknown[],
  blocked: readonly unknown[],
): Response {
  return new Response(
    JSON.stringify({ data: waivers, meta: { blocked_subjects: blocked } }),
  )
}

const blockedSubject = {
  subject_id: 21,
  subject_code: "CS201",
  subject_title: "Data Structures",
  reasons: ["CS101: Recorded mark 5.00 does not meet the required 3.00."],
}

describe("PrerequisiteWaiverSection", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => {
    vi.unstubAllGlobals()
    fetchMock.mockReset()
  })

  function renderSection() {
    return renderWithSession(
      <PrerequisiteWaiverSection studentId={4} academicTermId={2} />,
      { session: headSession },
    )
  }

  it("lists the granted waivers and the subjects blocked by a prerequisite", async () => {
    fetchMock.mockResolvedValue(overview([activeWaiver], [blockedSubject]))
    renderSection()

    expect(
      await screen.findByText(/CS101 — Intro to Computing/),
    ).toBeInTheDocument()
    expect(screen.getByText("Passed the bridging course.")).toBeInTheDocument()
    expect(screen.getByText("Active")).toBeInTheDocument()
    expect(screen.getByText(/CS201 — Data Structures/)).toBeInTheDocument()
    expect(
      screen.getByText(/does not meet the required 3.00/),
    ).toBeInTheDocument()
    expect(fetchMock.mock.calls[0]?.[0]).toContain(
      "/api/v1/students/4/subject-waivers?academic_term_id=2",
    )
  })

  it("shows friendly empty states", async () => {
    fetchMock.mockResolvedValue(overview([], []))
    renderSection()

    expect(
      await screen.findByText("No waivers have been granted this term."),
    ).toBeInTheDocument()
    expect(
      screen.getByText("No subject is blocked only by a prerequisite."),
    ).toBeInTheDocument()
  })

  it("needs a reason before it grants a waiver, then sends it", async () => {
    const user = userEvent.setup()
    const bodies: unknown[] = []
    fetchMock.mockImplementation((_input, init) => {
      if (init?.method === "POST") {
        bodies.push(
          JSON.parse(typeof init.body === "string" ? init.body : "{}"),
        )
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                ...activeWaiver,
                id: 9,
                subject_id: 21,
                subject_code: "CS201",
                subject_title: "Data Structures",
              },
            }),
            { status: 201 },
          ),
        )
      }
      return Promise.resolve(overview([], [blockedSubject]))
    })
    renderSection()

    await user.click(await screen.findByRole("button", { name: /Waive/ }))
    const grant = screen.getByRole("button", { name: "Grant waiver" })
    expect(grant).toBeDisabled()

    await user.type(
      screen.getByLabelText("Reason for waiving CS201"),
      "Failed CS101 but passed the bridging course.",
    )
    await user.click(grant)

    await waitFor(() =>
      expect(bodies[0]).toEqual({
        subject_id: 21,
        academic_term_id: 2,
        reason: "Failed CS101 but passed the bridging course.",
      }),
    )
  })

  it("takes an active waiver back", async () => {
    const user = userEvent.setup()
    const methods: string[] = []
    fetchMock.mockImplementation((_input, init) => {
      if (init?.method === "DELETE") {
        methods.push("DELETE")
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                ...activeWaiver,
                is_active: false,
                revoked_at: "2026-09-26T02:00:00Z",
              },
            }),
          ),
        )
      }
      return Promise.resolve(overview([activeWaiver], []))
    })
    renderSection()

    const item = (
      await screen.findByText(/CS101 — Intro to Computing/)
    ).closest("li")
    expect(item).not.toBeNull()
    await user.click(
      within(item as HTMLElement).getByRole("button", { name: /Take back/ }),
    )

    await waitFor(() => expect(methods).toEqual(["DELETE"]))
  })

  it("shows an error when the waivers cannot be loaded", async () => {
    fetchMock.mockResolvedValue(new Response("{}", { status: 403 }))
    renderSection()

    expect(
      await screen.findByText("The prerequisite waivers could not be loaded."),
    ).toBeInTheDocument()
  })
})
