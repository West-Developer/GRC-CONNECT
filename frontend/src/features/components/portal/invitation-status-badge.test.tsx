import { render, screen } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import { InvitationStatusBadge } from "@/features/components/portal/invitation-status-badge"

const NOW = new Date("2026-10-14T10:00:00Z")

function badge(
  overrides: Partial<Parameters<typeof InvitationStatusBadge>[0]["profile"]>,
) {
  render(
    <InvitationStatusBadge
      now={NOW}
      profile={{
        account_setup_status: "pending",
        invitation_delivery_status: "sent",
        enrollment_deadline_at: null,
        ...overrides,
      }}
    />,
  )
}

describe("InvitationStatusBadge", () => {
  it.each([
    [{ account_setup_status: "active" as const }, "Activated"],
    [{ account_setup_status: "awaiting_payment" as const }, "Awaiting payment"],
    [{ invitation_delivery_status: "failed" as const }, "Delivery failed"],
    [{ invitation_delivery_status: "sent" as const }, "Awaiting setup"],
    [{ invitation_delivery_status: "not_sent" as const }, "Pending delivery"],
  ])("reads %j as %s", (overrides, label) => {
    badge(overrides)

    expect(screen.getByText(label)).toBeInTheDocument()
  })

  it("says the deadline passed for a pending account whose 7 days ran out, so a resend reads as admitting them again", () => {
    badge({ enrollment_deadline_at: "2026-10-14T09:00:00Z" })

    expect(screen.getByText("Deadline passed")).toBeInTheDocument()
    expect(screen.queryByText("Awaiting setup")).not.toBeInTheDocument()
  })

  it("keeps the usual state while the deadline is still ahead", () => {
    badge({ enrollment_deadline_at: "2026-10-20T09:00:00Z" })

    expect(screen.getByText("Awaiting setup")).toBeInTheDocument()
  })

  it("never calls an activated account past its deadline anything but activated", () => {
    badge({
      account_setup_status: "active",
      enrollment_deadline_at: "2026-10-14T09:00:00Z",
    })

    expect(screen.getByText("Activated")).toBeInTheDocument()
  })
})
