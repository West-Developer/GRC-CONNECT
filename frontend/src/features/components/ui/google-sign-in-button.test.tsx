import { screen, waitFor } from "@testing-library/react"
import { afterEach, describe, expect, it, vi } from "vitest"

import { AuthError } from "@/features/auth/auth-error"
import { GoogleSignInButton } from "@/features/components/ui/google-sign-in-button"
import { renderWithSession } from "@/tests/render-app"

vi.mock("next/script", () => ({
  // The real component only needs the load callback to fire; jsdom never
  // actually loads the external script.
  default: ({ onLoad }: { onLoad?: () => void }) => {
    onLoad?.()
    return null
  },
}))

function stubGoogleIdentityServices() {
  const initialize = vi.fn<
    (config: {
      client_id: string
      callback: (response: { credential: string }) => void
    }) => void
  >()
  const renderButton = vi.fn<
    (parent: HTMLElement, options: Record<string, unknown>) => void
  >()
  window.google = { accounts: { id: { initialize, renderButton } } }

  return { initialize, renderButton }
}

const CLIENT_ID = "test-client-id.apps.googleusercontent.com"

describe("GoogleSignInButton", () => {
  const originalClientId = process.env.NEXT_PUBLIC_GOOGLE_CLIENT_ID

  afterEach(() => {
    process.env.NEXT_PUBLIC_GOOGLE_CLIENT_ID = originalClientId
    delete (window as { google?: unknown }).google
  })

  it("renders nothing when no client ID is configured", () => {
    process.env.NEXT_PUBLIC_GOOGLE_CLIENT_ID = ""
    const { container } = renderWithSession(
      <GoogleSignInButton onSignedIn={vi.fn()} />,
    )

    expect(container).toBeEmptyDOMElement()
  })

  it("initializes and renders Google's own button once the script loads", () => {
    process.env.NEXT_PUBLIC_GOOGLE_CLIENT_ID = CLIENT_ID
    const { initialize, renderButton } = stubGoogleIdentityServices()

    renderWithSession(<GoogleSignInButton onSignedIn={vi.fn()} />)

    expect(initialize).toHaveBeenCalledWith(
      expect.objectContaining({ client_id: CLIENT_ID }),
    )
    expect(renderButton).toHaveBeenCalledTimes(1)
  })

  it("signs in and calls onSignedIn once Google returns a credential", async () => {
    process.env.NEXT_PUBLIC_GOOGLE_CLIENT_ID = CLIENT_ID
    const { initialize } = stubGoogleIdentityServices()
    const onSignedIn = vi.fn()
    const signInWithGoogle = vi.fn().mockResolvedValue({
      userId: "1",
      displayName: "Test Student",
      role: "student",
      signedInAt: "2026-01-01T00:00:00.000Z",
    })

    renderWithSession(<GoogleSignInButton onSignedIn={onSignedIn} />, {
      signInWithGoogle,
    })

    const { callback } = initialize.mock.calls[0]?.[0] as {
      callback: (response: { credential: string }) => void
    }
    callback({ credential: "fake-google-credential" })

    await waitFor(() => {
      expect(signInWithGoogle).toHaveBeenCalledWith("fake-google-credential")
    })
    await waitFor(() => expect(onSignedIn).toHaveBeenCalledTimes(1))
  })

  it("shows the contact-Admission message when the Google email matches no account", async () => {
    process.env.NEXT_PUBLIC_GOOGLE_CLIENT_ID = CLIENT_ID
    const { initialize } = stubGoogleIdentityServices()
    const onSignedIn = vi.fn()
    const signInWithGoogle = vi
      .fn()
      .mockRejectedValue(new AuthError("GOOGLE_ACCOUNT_NOT_FOUND"))

    renderWithSession(<GoogleSignInButton onSignedIn={onSignedIn} />, {
      signInWithGoogle,
    })

    const { callback } = initialize.mock.calls[0]?.[0] as {
      callback: (response: { credential: string }) => void
    }
    callback({ credential: "fake-google-credential" })

    expect(
      await screen.findByText(/contact Admission/i),
    ).toBeInTheDocument()
    expect(onSignedIn).not.toHaveBeenCalled()
  })

  it("shows a generic message for any other sign-in failure", async () => {
    process.env.NEXT_PUBLIC_GOOGLE_CLIENT_ID = CLIENT_ID
    const { initialize } = stubGoogleIdentityServices()
    const signInWithGoogle = vi.fn().mockRejectedValue(new Error("boom"))

    renderWithSession(<GoogleSignInButton onSignedIn={vi.fn()} />, {
      signInWithGoogle,
    })

    const { callback } = initialize.mock.calls[0]?.[0] as {
      callback: (response: { credential: string }) => void
    }
    callback({ credential: "fake-google-credential" })

    expect(
      await screen.findByText(/could not be completed/i),
    ).toBeInTheDocument()
  })
})
