import { render, screen, waitFor } from "@testing-library/react"
import { afterEach, describe, expect, it, vi } from "vitest"

import { Providers } from "@/app/providers"
import { useAuth } from "@/features/auth/use-auth"
import { QueueKioskPage } from "@/features/components/pages/queue-kiosk-page"
import { setLocation } from "@/tests/navigation-mock"

function PortalProbe() {
  const { status } = useAuth()
  return <output aria-label="portal auth status">{status}</output>
}

describe("Providers route isolation", () => {
  afterEach(() => {
    localStorage.clear()
    vi.restoreAllMocks()
  })

  it("does not read the portal token or start portal restore on /queue", async () => {
    setLocation("/queue")
    localStorage.setItem("grc.auth-token.v1", "portal-token")
    const getItem = vi.spyOn(Storage.prototype, "getItem")
    const fetchMock = vi.fn<typeof fetch>()
    vi.stubGlobal("fetch", fetchMock)

    render(
      <Providers>
        <QueueKioskPage />
      </Providers>,
    )

    await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
    expect(getItem).not.toHaveBeenCalledWith("grc.auth-token.v1")
    expect(fetchMock).not.toHaveBeenCalled()
    vi.unstubAllGlobals()
  })

  it("does not read the portal token or start portal restore on the public live queue link", async () => {
    setLocation("/queue/live?t=abc")
    localStorage.setItem("grc.auth-token.v1", "portal-token")
    const getItem = vi.spyOn(Storage.prototype, "getItem")
    const fetchMock = vi.fn<typeof fetch>()
    vi.stubGlobal("fetch", fetchMock)

    render(
      <Providers>
        <p>public queue stand-in</p>
      </Providers>,
    )

    await screen.findByText("public queue stand-in")
    // A freshman opening their emailed link is not a portal user: no token read, no /auth/me.
    expect(getItem).not.toHaveBeenCalledWith("grc.auth-token.v1")
    expect(fetchMock).not.toHaveBeenCalled()
    vi.unstubAllGlobals()
  })

  it("still mounts the portal AuthProvider away from /queue", async () => {
    setLocation("/portal")
    render(
      <Providers>
        <PortalProbe />
      </Providers>,
    )

    await waitFor(() =>
      expect(screen.getByLabelText("portal auth status")).toHaveTextContent(
        "anonymous",
      ),
    )
  })
})
