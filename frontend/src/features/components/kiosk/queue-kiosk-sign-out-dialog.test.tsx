import { render, screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { QueueKioskSignOutDialog } from "./queue-kiosk-sign-out-dialog"
import { agreedPrivacyNotice } from "@/tests/privacy-notice-fixture"

describe("QueueKioskSignOutDialog", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it("does not render contents when closed", () => {
    render(
      <QueueKioskSignOutDialog
        open={false}
        onOpenChange={() => {}}
        onConfirmSignOut={() => {}}
      />,
    )
    expect(screen.queryByText("Confirm Device Sign Out")).not.toBeInTheDocument()
  })

  it("prompts for password and calls onConfirmSignOut when password is valid", async () => {
    const user = userEvent.setup()
    const onConfirmSignOut = vi.fn()
    const onOpenChange = vi.fn()

    // Successful login returns session and logout returns 204
    fetchMock
      .mockResolvedValueOnce(
        new Response(
          JSON.stringify({
            data: {
              type: "auth-session",
              token: "new-token",
              token_type: "Bearer",
              expires_at: null,
              user: {
                type: "user",
                id: 11,
                name: "Queue Kiosk",
                email: "queue@grc.com",
                role: "queue_kiosk",
                role_label: "Queue Kiosk",
                privacy_notice: agreedPrivacyNotice,
                college: null,
                status: "active",
              },
            },
          }),
          { status: 200, headers: { "Content-Type": "application/json" } },
        )
      )
      .mockResolvedValueOnce(new Response(null, { status: 204 }))

    render(
      <QueueKioskSignOutDialog
        open={true}
        onOpenChange={onOpenChange}
        onConfirmSignOut={onConfirmSignOut}
        kioskEmail="queue@grc.com"
      />,
    )

    expect(screen.getByText("Confirm Device Sign Out")).toBeInTheDocument()
    const input = screen.getByPlaceholderText("Enter queue password")
    await user.type(input, "correct-password")
    await user.click(screen.getByRole("button", { name: "Verify & Sign out" }))

    await waitFor(() => {
      expect(onConfirmSignOut).toHaveBeenCalledTimes(1)
    })
    expect(onOpenChange).toHaveBeenCalledWith(false)
  })

  it("displays error message when password verification fails", async () => {
    const user = userEvent.setup()
    const onConfirmSignOut = vi.fn()
    const onOpenChange = vi.fn()

    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ error: { message: "Invalid credentials" } }), {
        status: 401,
        headers: { "Content-Type": "application/json" },
      }),
    )

    render(
      <QueueKioskSignOutDialog
        open={true}
        onOpenChange={onOpenChange}
        onConfirmSignOut={onConfirmSignOut}
        kioskEmail="queue@grc.com"
      />,
    )

    const input = screen.getByPlaceholderText("Enter queue password")
    await user.type(input, "wrong-password")
    await user.click(screen.getByRole("button", { name: "Verify & Sign out" }))

    await screen.findByText("Incorrect password. Device sign-out aborted.")
    expect(onConfirmSignOut).not.toHaveBeenCalled()
  })
})
