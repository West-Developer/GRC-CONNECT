import { screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { AuditLogsWorkspace } from "@/features/components/portal/audit-logs-workspace"
import { renderWithSession } from "@/tests/render-app"

const links = {
  first: "https://api.test/audit-logs?page=1",
  last: "https://api.test/audit-logs?page=1",
  prev: null,
  next: null,
}

const auditActors = {
  data: [
    {
      type: "audit_actor",
      actor_user_id: 7,
      actor_name: "Dana Dean",
      actor_role: "dean",
      actor_role_label: "Dean",
      entries_count: 2,
      last_activity_at: "2026-07-29T12:00:00Z",
    },
    {
      type: "audit_actor",
      actor_user_id: 9,
      actor_name: "Pat Program Head",
      actor_role: "program_chair",
      actor_role_label: "Program Head",
      entries_count: 1,
      last_activity_at: "2026-07-28T12:00:00Z",
    },
    {
      type: "audit_actor",
      actor_user_id: 11,
      actor_name: "Sam Super",
      actor_role: "super_admin",
      actor_role_label: "Super Admin",
      entries_count: 3,
      last_activity_at: "2026-07-27T12:00:00Z",
    },
  ],
  links,
  meta: { current_page: 1, last_page: 1, per_page: 20, total: 3 },
}

const deanEntries = {
  data: [
    {
      type: "audit_log",
      id: 1,
      actor_user_id: 7,
      actor_name: "Dana Dean",
      actor_role: "dean",
      actor_role_label: "Dean",
      // The API always sends the acting-context keys; they are null unless a Super Admin acted
      // through the department switcher (the old fixture omitted them, which hid a strict-schema bug).
      acting_role: null,
      acting_role_label: null,
      acting_college: null,
      action: "section.updated",
      auditable_type: "section",
      auditable_id: 3,
      before_values: { status: "planned", room: "R101" },
      after_values: { status: "published", room: "R101" },
      changes: [
        {
          field: "status",
          label: "Status",
          old: "planned",
          new: "published",
          changed: true,
        },
        {
          field: "room",
          label: "Room",
          old: "R101",
          new: "R101",
          changed: false,
        },
      ],
      reason: "Ready for enrollment",
      request_id: "req-1",
      ip_address: "127.0.0.1",
      created_at: "2026-07-29T12:00:00Z",
    },
  ],
  links,
  meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 },
}

const superAdminEntries = {
  data: [
    {
      ...deanEntries.data[0],
      id: 2,
      actor_user_id: 11,
      actor_name: "Sam Super",
      actor_role: "super_admin",
      actor_role_label: "Super Admin",
      acting_role: "dean",
      acting_role_label: "Dean",
      acting_college: "ccs",
    },
  ],
  links,
  meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 },
}

function url(input: RequestInfo | URL) {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : input.url
}

function respond(input: RequestInfo | URL) {
  const target = url(input)
  if (target.includes("schedule-proposals")) return { data: [] }
  if (target.includes("/audit-logs/actors")) return auditActors
  if (target.includes("actor_user_id=7")) return deanEntries
  if (target.includes("actor_user_id=11")) return superAdminEntries
  return { data: [], links, meta: { ...deanEntries.meta, total: 0 } }
}

const registrarHead = {
  userId: "8",
  displayName: "Registrar",
  role: "registrar_head" as const,
  signedInAt: "2026-07-29T12:00:00Z",
}

describe("AuditLogsWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    fetchMock.mockImplementation((input) =>
      Promise.resolve(new Response(JSON.stringify(respond(input)))),
    )
  })
  afterEach(() => vi.unstubAllGlobals())

  it("lists one card per person with their role and how many records they have", async () => {
    renderWithSession(<AuditLogsWorkspace />, { session: registrarHead })

    expect(await screen.findByText("Dana Dean")).toBeInTheDocument()
    expect(screen.getByText("Pat Program Head")).toBeInTheDocument()
    expect(screen.getByText("Program Head")).toBeInTheDocument()
    expect(screen.getByText("2 records")).toBeInTheDocument()
    expect(screen.getByText("1 record")).toBeInTheDocument()
    // Nothing about a person's records is fetched until their card is open.
    expect(fetchMock).not.toHaveBeenCalledWith(
      expect.stringContaining("actor_user_id="),
      expect.anything(),
    )
  })

  it("shows which role and college a Super Admin was acting as on their records", async () => {
    const user = userEvent.setup()
    renderWithSession(<AuditLogsWorkspace />, { session: registrarHead })

    await user.click(await screen.findByRole("button", { name: /Sam Super/ }))

    expect(await screen.findByText("Section updated")).toBeInTheDocument()
    expect(screen.getByText("Acting as Dean · CCS")).toBeInTheDocument()
  })

  it("loads a person's records when their card opens and compares old with new", async () => {
    const user = userEvent.setup()
    renderWithSession(<AuditLogsWorkspace />, { session: registrarHead })

    await user.click(await screen.findByRole("button", { name: /Dana Dean/ }))

    expect(await screen.findByText("Section updated")).toBeInTheDocument()
    expect(screen.getByText("Section #3")).toBeInTheDocument()
    expect(screen.getByText(/Ready for enrollment/)).toBeInTheDocument()
    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining("actor_user_id=7"),
      expect.anything(),
    )

    await user.click(screen.getByText(/1 change — compare old and new/))
    const changed = await screen.findByRole("list", { name: "Changed fields" })
    expect(within(changed).getByText("Status")).toBeInTheDocument()
    expect(within(changed).getByText("planned")).toBeInTheDocument()
    expect(within(changed).getByText("published")).toBeInTheDocument()
    // The field that kept its value is tucked into a muted, collapsed list.
    expect(screen.getByText("Show 1 unchanged field")).toBeInTheDocument()
  })

  it("applies the date filters to the person list and to their records", async () => {
    const user = userEvent.setup()
    renderWithSession(<AuditLogsWorkspace />, { session: registrarHead })

    await user.type(await screen.findByLabelText("From"), "2026-07-01")
    await user.click(
      screen.getByRole("button", { name: "Apply audit filters" }),
    )

    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining("from=2026-07-01"),
      expect.anything(),
    )
    await user.click(await screen.findByRole("button", { name: /Dana Dean/ }))
    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringMatching(
        /actor_user_id=7.*from=2026-07-01|from=2026-07-01.*actor_user_id=7/,
      ),
      expect.anything(),
    )
  })

  it("says so when nothing matches", async () => {
    fetchMock.mockImplementation((input) =>
      Promise.resolve(
        new Response(
          JSON.stringify(
            url(input).includes("/audit-logs/actors")
              ? { data: [], links, meta: { ...auditActors.meta, total: 0 } }
              : { data: [] },
          ),
        ),
      ),
    )
    renderWithSession(<AuditLogsWorkspace />, { session: registrarHead })

    expect(
      await screen.findByText("No audit records match these filters."),
    ).toBeInTheDocument()
  })

  it("does not fetch or render audit records for an unauthorized role", () => {
    renderWithSession(<AuditLogsWorkspace />, {
      session: {
        userId: "6",
        displayName: "Executive",
        role: "executive_director",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })
    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("renders audit records when authorized as super_admin", async () => {
    renderWithSession(<AuditLogsWorkspace />, {
      session: {
        userId: "99",
        displayName: "Super Admin",
        role: "super_admin",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })
    expect(await screen.findByText("Dana Dean")).toBeInTheDocument()
  })

  it("has no detectable accessibility violations once loaded", async () => {
    const { container } = renderWithSession(<AuditLogsWorkspace />, {
      session: registrarHead,
    })
    await screen.findByText("Dana Dean")
    expect(await axe(container)).toHaveNoViolations()
  })
})
