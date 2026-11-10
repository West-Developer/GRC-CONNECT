import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { ProgramChairIrregularEnrollmentsWorkspace } from "@/features/components/portal/program-chair-irregular-enrollments-workspace"
import { renderWithSession } from "@/tests/render-app"

function urlOf(input: RequestInfo | URL): string {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

const paginationLinks = {
  first: "https://api.test/enrollments?page=1",
  last: "https://api.test/enrollments?page=1",
  prev: null,
  next: null,
}
const paginationMeta = {
  current_page: 1,
  last_page: 1,
  per_page: 20,
  total: 1,
}

const awaitingProgramHead = {
  type: "enrollment",
  id: 21,
  student_id: 8,
  student_number: "2026-0300",
  student_name: "Irregular Student",
  student_year_level: 3,
  student_financial_status: null,
  student_financial_status_label: null,
  student_enrollment_category: "irregular",
  is_irregular: true,
  academic_term_id: 2,
  status: "pending_program_head_approval",
  status_label: "Pending Program Head Approval",
  total_units: 18,
  requires_overload_approval: false,
  program_head_comment: null,
  submitted_at: "2026-07-30T00:00:00Z",
  program_head_decided_at: null,
  registrar_decided_at: null,
  payment_confirmed_at: null,
  enrolled_at: null,
  subjects: [],
  queue_ticket: null,
  assessment: null,
} as const

const programHeadSession = {
  userId: "7",
  displayName: "Program Head",
  role: "program_chair",
  college: "ccs",
  signedInAt: "2026-07-29T12:00:00Z",
} as const

describe("ProgramChairIrregularEnrollmentsWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => {
    vi.unstubAllGlobals()
    fetchMock.mockReset()
  })

  function listResponse(rows: readonly unknown[]) {
    return new Response(
      JSON.stringify({
        data: rows,
        links: paginationLinks,
        meta: paginationMeta,
      }),
    )
  }

  it("lists what awaits the Program Head first and forwards an approval to the Registrar", async () => {
    const user = userEvent.setup()
    const patchBodies: unknown[] = []
    fetchMock.mockImplementation((input, init) => {
      if (init?.method === "PATCH") {
        patchBodies.push(
          JSON.parse(typeof init.body === "string" ? init.body : "{}"),
        )
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                ...awaitingProgramHead,
                status: "pending_registrar_approval",
                status_label: "Pending Registrar Approval",
                program_head_decided_at: "2026-07-31T00:00:00Z",
              },
            }),
          ),
        )
      }
      expect(urlOf(input)).toContain("status=pending_program_head_approval")
      return Promise.resolve(listResponse([awaitingProgramHead]))
    })
    renderWithSession(<ProgramChairIrregularEnrollmentsWorkspace />, {
      session: programHeadSession,
    })

    const table = await screen.findByRole("table")
    await user.click(within(table).getByRole("button", { name: "Approve" }))

    const dialog = await screen.findByRole("alertdialog")
    expect(
      within(dialog).getByText(/sends it on to the Registrar/),
    ).toBeInTheDocument()
    await user.click(
      within(dialog).getByRole("button", { name: /confirm|approve/i }),
    )

    await waitFor(() =>
      expect(patchBodies[0]).toMatchObject({ action: "program_head_approve" }),
    )
  })

  it("flags an enrollment that is over the unit maximum and can narrow the list to those", async () => {
    const user = userEvent.setup()
    const over = {
      ...awaitingProgramHead,
      id: 22,
      student_number: "2026-0301",
      student_name: "Overloaded Student",
      total_units: 38.5,
      requires_overload_approval: true,
      unit_maximum: 30.5,
      exceeds_unit_maximum: true,
    }
    fetchMock.mockImplementation((input) => {
      const target = urlOf(input)
      if (!target.includes("/enrollments")) {
        return Promise.resolve(new Response(JSON.stringify({ data: [] })))
      }
      return Promise.resolve(
        listResponse(
          target.includes("exceeds_unit_maximum=1")
            ? [over]
            : [awaitingProgramHead, over],
        ),
      )
    })
    renderWithSession(<ProgramChairIrregularEnrollmentsWorkspace />, {
      session: programHeadSession,
    })

    const table = await screen.findByRole("table")
    const row = within(table).getByText("Overloaded Student").closest("tr")!
    expect(
      within(row).getByText("Over the 30.5 unit maximum"),
    ).toBeInTheDocument()
    // The other student is within the maximum and carries no such flag.
    const other = within(table).getByText("Irregular Student").closest("tr")!
    expect(within(other).queryByText(/unit maximum/)).not.toBeInTheDocument()

    await user.click(
      screen.getByRole("button", { name: "Over the unit maximum" }),
    )

    await waitFor(() =>
      expect(
        fetchMock.mock.calls.some(([input]) =>
          urlOf(input).includes("exceeds_unit_maximum=1"),
        ),
      ).toBe(true),
    )
    await waitFor(() =>
      expect(
        within(table).queryByText("Irregular Student"),
      ).not.toBeInTheDocument(),
    )
    expect(
      screen.getByRole("button", { name: "Over the unit maximum" }),
    ).toHaveAttribute("aria-pressed", "true")
  })

  it("keeps a long status in a flex column so its table column cannot shrink below the badge and spill over Units", async () => {
    // A badge is overflow-hidden: inside a grid wrapper the column could shrink under it (owner, 2026-10-15,
    // "Pending Program Head Approval" overlapping the units). jsdom has no layout, so the structure is pinned.
    fetchMock.mockImplementation(() =>
      Promise.resolve(listResponse([awaitingProgramHead])),
    )
    renderWithSession(<ProgramChairIrregularEnrollmentsWorkspace />, {
      session: programHeadSession,
    })

    const table = await screen.findByRole("table")
    const badge = within(table).getByText("Pending Program Head Approval")
    const wrapper = badge.parentElement

    expect(wrapper).toHaveClass("flex", "flex-col", "items-start")
    expect(wrapper).not.toHaveClass("grid")
  })

  it("requires a reason before a rejection is sent as a Program Head decision", async () => {
    const user = userEvent.setup()
    const patchBodies: unknown[] = []
    fetchMock.mockImplementation((_input, init) => {
      if (init?.method === "PATCH") {
        patchBodies.push(
          JSON.parse(typeof init.body === "string" ? init.body : "{}"),
        )
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                ...awaitingProgramHead,
                status: "rejected",
                status_label: "Rejected",
              },
            }),
          ),
        )
      }
      return Promise.resolve(listResponse([awaitingProgramHead]))
    })
    renderWithSession(<ProgramChairIrregularEnrollmentsWorkspace />, {
      session: programHeadSession,
    })

    const table = await screen.findByRole("table")
    await user.click(within(table).getByRole("button", { name: "Reject" }))

    const dialog = await screen.findByRole("alertdialog")
    const confirm = within(dialog).getByRole("button", {
      name: /reject|confirm/i,
    })
    expect(confirm).toBeDisabled()
    await user.type(
      within(dialog).getByLabelText("Reason for rejection"),
      "Schedule clash",
    )
    await user.click(confirm)

    await waitFor(() =>
      expect(patchBodies[0]).toMatchObject({
        action: "program_head_reject",
        reason: "Schedule clash",
      }),
    )
  })

  it("offers a With Registrar view for enrollments already forwarded", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input) => {
      const target = urlOf(input)
      return Promise.resolve(
        listResponse(
          target.includes("status=pending_registrar_approval")
            ? [
                {
                  ...awaitingProgramHead,
                  status: "pending_registrar_approval",
                  status_label: "Pending Registrar Approval",
                  program_head_decided_at: "2026-07-31T00:00:00Z",
                },
              ]
            : [awaitingProgramHead],
        ),
      )
    })
    renderWithSession(<ProgramChairIrregularEnrollmentsWorkspace />, {
      session: programHeadSession,
    })

    await screen.findByRole("table")
    await user.click(screen.getByRole("button", { name: "With Registrar" }))

    await waitFor(() =>
      expect(
        fetchMock.mock.calls.some(([input]) =>
          urlOf(input).includes("status=pending_registrar_approval"),
        ),
      ).toBe(true),
    )
    const table = await screen.findByRole("table")
    await waitFor(() =>
      expect(
        within(table).queryByRole("button", { name: "Approve" }),
      ).not.toBeInTheDocument(),
    )
  })
})
