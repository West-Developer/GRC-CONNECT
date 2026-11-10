import { focusManager } from "@tanstack/react-query"
import { act, screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import type { AuthSession } from "@/features/auth/auth-types"
import { userRoles, type UserRole } from "@/features/auth/roles"
import { PortalOverviewPage } from "@/features/components/pages/portal-overview-page"
import { isConnectedModuleId } from "@/features/portal/module-registry"
import { rolePortalDefinitions } from "@/features/portal/role-capabilities"
import { renderWithSession } from "@/tests/render-app"

const studentSession: AuthSession = {
  userId: "1",
  displayName: "Test Student",
  role: "student",
  signedInAt: "2026-07-26T12:00:00.000Z",
}

const liveQueueView = {
  type: "student_queue_view",
  stage: "pending_payment",
  can_claim: false,
  ticket: {
    ticket_number: "Q-LIVE-007",
    status: "waiting",
    status_label: "Waiting",
    priority: "regular",
    priority_label: "Regular",
    position: 2,
  },
  now_serving_ticket_number: "Q-LIVE-005",
  upcoming_ticket_numbers: ["Q-LIVE-006", "Q-LIVE-007"],
  cut_off_today: false,
} as const

function sessionFor(role: UserRole): AuthSession {
  return {
    ...studentSession,
    displayName: `Test ${rolePortalDefinitions[role].roleLabel}`,
    role,
  }
}

function requestUrl(input: RequestInfo | URL): string {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

describe("PortalOverviewPage", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    fetchMock.mockImplementation(() => new Promise<Response>(() => undefined))
    vi.stubGlobal("fetch", fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it("shows GRC Connect role guidance without repeating the signed-in name", async () => {
    renderWithSession(<PortalOverviewPage />, {
      route: "/portal",
      session: studentSession,
    })

    expect(
      await screen.findByRole("heading", {
        name: "GRC Connect",
      }),
    ).toBeInTheDocument()
    expect(screen.queryByText("Welcome, Test Student.")).not.toBeInTheDocument()
    expect(
      screen.getByText(rolePortalDefinitions.student.welcomeHeading),
    ).toBeInTheDocument()
    expect(screen.getByText("Loading academic term…")).toBeInTheDocument()
    expect(
      screen.getByText("Checking system availability…"),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("link", { name: "Continue to Enrollment" }),
    ).toBeInTheDocument()
  })

  it.each(userRoles)(
    "does not repeat the signed-in name in the GRC Connect hero for %s",
    async (role) => {
      const session = sessionFor(role)

      renderWithSession(<PortalOverviewPage />, {
        route: "/portal",
        session,
      })
      await screen.findByRole("heading", { name: "GRC Connect" })

      expect(
        screen.queryByText(`Welcome, ${session.displayName}.`),
      ).not.toBeInTheDocument()
      expect(
        screen.getByText(rolePortalDefinitions[role].welcomeHeading),
      ).toBeInTheDocument()
      expect(
        screen.queryByText(rolePortalDefinitions[role].roleLabel),
      ).not.toBeInTheDocument()
    },
  )

  it("renders each assigned module under the GRC Connect workspace", async () => {
    renderWithSession(<PortalOverviewPage />, {
      route: "/portal",
      session: studentSession,
    })
    await screen.findByRole("heading", {
      name: "GRC Connect",
    })

    const modules = screen.getByRole("region", {
      name: "Student GRC Connect modules",
    })
    const links = within(modules).getAllByRole("link")

    expect(links).toHaveLength(rolePortalDefinitions.student.modules.length)

    for (const module of rolePortalDefinitions.student.modules) {
      expect(
        within(modules).getByRole("link", {
          name:
            module.id === "enrollment"
              ? `Continue to ${module.label}`
              : `Open ${module.label}`,
        }),
      ).toHaveAttribute("href", `/portal/${module.id}`)
    }
  })

  it("does not expose another role's modules", async () => {
    renderWithSession(<PortalOverviewPage />, {
      route: "/portal",
      session: studentSession,
    })
    await screen.findByRole("heading", {
      name: "GRC Connect",
    })

    const accountingOnly =
      rolePortalDefinitions.accounting_staff.modules[0].label
    expect(screen.queryByText(accountingOnly)).not.toBeInTheDocument()
    expect(document.body).not.toHaveTextContent("Accounting")
  })

  it("separates planned capabilities from the role's available workspaces", async () => {
    const deanSession: AuthSession = {
      ...studentSession,
      role: "dean",
      displayName: "Test Dean",
    }
    const definition = rolePortalDefinitions.dean
    const availableModules = definition.modules.filter((module) =>
      isConnectedModuleId(module.id),
    )
    const plannedModules = definition.modules.filter(
      (module) => !isConnectedModuleId(module.id),
    )

    renderWithSession(<PortalOverviewPage />, {
      route: "/portal",
      session: deanSession,
    })
    await screen.findByRole("heading", { name: "GRC Connect" })

    const availableWorkspace = screen.getByRole("region", {
      name: "Dean GRC Connect modules",
    })
    const plannedCapabilities = screen.getByRole("region", {
      name: "Dean planned capabilities",
    })

    expect(screen.getByText("Available workspaces")).toBeInTheDocument()
    expect(screen.getByText("Planned capabilities")).toBeInTheDocument()
    expect(screen.getByText("Start here")).toBeInTheDocument()

    for (const [index, module] of availableModules.entries()) {
      const actionLabel =
        index === 0 ? `Continue to ${module.label}` : `Open ${module.label}`

      expect(
        within(availableWorkspace).getByRole("link", {
          name: actionLabel,
        }),
      ).toHaveAttribute("href", `/portal/${module.id}`)
    }

    for (const module of plannedModules) {
      expect(
        within(availableWorkspace).queryByText(module.label),
      ).not.toBeInTheDocument()
      expect(
        within(plannedCapabilities).getByText(module.label),
      ).toBeInTheDocument()
      expect(
        within(plannedCapabilities).queryByRole("link", {
          name: new RegExp(module.label),
        }),
      ).not.toBeInTheDocument()
    }
  })

  it("shows a compact live Cashier queue for the signed-in Student", async () => {
    fetchMock.mockImplementation((input) => {
      if (requestUrl(input).includes("/api/v1/queue-status")) {
        return Promise.resolve(
          new Response(JSON.stringify({ data: liveQueueView })),
        )
      }

      return new Promise<Response>(() => undefined)
    })

    renderWithSession(<PortalOverviewPage />, {
      route: "/portal",
      session: studentSession,
    })

    const queueRegion = await screen.findByRole("region", {
      name: "Your Cashier queue",
    })
    expect(within(queueRegion).getByText("Q-LIVE-007")).toBeInTheDocument()
    expect(within(queueRegion).getByText("Q-LIVE-005")).toBeInTheDocument()
    expect(
      within(queueRegion).queryByRole("list", {
        name: "Upcoming ticket numbers",
      }),
    ).not.toBeInTheDocument()
  })

  it("shows queue loading without a stale queue panel", async () => {
    renderWithSession(<PortalOverviewPage />, {
      route: "/portal",
      session: studentSession,
    })

    expect(await screen.findByText("Loading queue status")).toBeInTheDocument()
    expect(
      screen.queryByRole("region", { name: "Your Cashier queue" }),
    ).not.toBeInTheDocument()
  })

  it("shows a queue error, retries it, and replaces the error with fresh data", async () => {
    const user = userEvent.setup()
    let queueAttempt = 0
    fetchMock.mockImplementation((input) => {
      if (!requestUrl(input).includes("/api/v1/queue-status")) {
        return new Promise<Response>(() => undefined)
      }
      queueAttempt++
      return Promise.resolve(
        queueAttempt === 1
          ? new Response(
              JSON.stringify({
                error: {
                  code: "QUEUE_UNAVAILABLE",
                  message: "Queue temporarily unavailable.",
                  errors: {},
                  request_id: "queue-retry-1",
                },
              }),
              { status: 400 },
            )
          : new Response(JSON.stringify({ data: liveQueueView })),
      )
    })
    renderWithSession(<PortalOverviewPage />, {
      route: "/portal",
      session: studentSession,
    })

    const retry = await screen.findByRole("button", {
      name: "Retry queue status",
    })
    expect(
      screen.queryByRole("region", { name: "Your Cashier queue" }),
    ).not.toBeInTheDocument()
    await user.click(retry)
    expect(
      await screen.findByRole("region", { name: "Your Cashier queue" }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: "Retry queue status" }),
    ).not.toBeInTheDocument()
  })

  it("removes stale queue data when a background refetch fails", async () => {
    let queueAttempt = 0
    fetchMock.mockImplementation((input) => {
      if (!requestUrl(input).includes("/api/v1/queue-status")) {
        return new Promise<Response>(() => undefined)
      }
      queueAttempt++
      return Promise.resolve(
        queueAttempt === 1
          ? new Response(JSON.stringify({ data: liveQueueView }))
          : new Response(
              JSON.stringify({
                error: {
                  code: "QUEUE_UNAVAILABLE",
                  message: "Queue temporarily unavailable.",
                  errors: {},
                  request_id: "queue-stale-1",
                },
              }),
              { status: 400 },
            ),
      )
    })
    renderWithSession(<PortalOverviewPage />, {
      route: "/portal",
      session: studentSession,
    })
    await screen.findByRole("region", { name: "Your Cashier queue" })

    act(() => {
      focusManager.setFocused(false)
      focusManager.setFocused(true)
    })
    await screen.findByRole("button", { name: "Retry queue status" })
    await waitFor(() =>
      expect(
        screen.queryByRole("region", { name: "Your Cashier queue" }),
      ).not.toBeInTheDocument(),
    )
  })

  it.each(userRoles.filter((role) => role !== "student"))(
    "does not fetch or render the Student Cashier queue for %s",
    async (role) => {
      renderWithSession(<PortalOverviewPage />, {
        route: "/portal",
        session: sessionFor(role),
      })

      await screen.findByRole("heading", { name: "GRC Connect" })

      expect(
        fetchMock.mock.calls.some(([input]) =>
          requestUrl(input).includes("/api/v1/queue-status"),
        ),
      ).toBe(false)
      expect(
        screen.queryByRole("region", { name: "Your Cashier queue" }),
      ).not.toBeInTheDocument()
    },
  )
})
