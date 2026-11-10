import { render, screen, waitFor } from "@testing-library/react"
import { useState } from "react"
import userEvent from "@testing-library/user-event"
import { afterEach, describe, expect, it, vi } from "vitest"

import { AuthProvider } from "@/features/auth/auth-context"
import type { AuthGateway, AuthSession } from "@/features/auth/auth-types"
import { useAuth } from "@/features/auth/use-auth"
import { getAuthenticatedJson } from "@/features/services/api-client"
import { browserFormDraftStore } from "@/features/services/form-draft-store"

const validSession: AuthSession = {
  userId: "1",
  displayName: "Test Student",
  role: "student",
  signedInAt: "2026-07-26T12:00:00.000Z",
}

function createGateway(overrides: Partial<AuthGateway> = {}): AuthGateway {
  return {
    signIn: () => Promise.resolve(validSession),
    verifyLoginOtp: () =>
      Promise.reject(new Error("verifyLoginOtp: not implemented in stub")),
    resendLoginOtp: () =>
      Promise.reject(new Error("resendLoginOtp: not implemented in stub")),
    signInWithGoogle: () =>
      Promise.reject(new Error("signInWithGoogle: not implemented in stub")),
    restore: () => Promise.resolve(null),
    signOut: () => Promise.resolve(),
    clearSession: () => undefined,
    persistenceAvailable: () => true,
    ...overrides,
  }
}

function AuthProbe() {
  const { session, signIn, signOut, status, storageAvailable } = useAuth()
  const [signInResult, setSignInResult] = useState<AuthSession | null>(null)

  return (
    <>
      <output aria-label="auth state">
        {status}:{session?.displayName ?? "none"}:
        {storageAvailable ? "persistence-on" : "persistence-off"}
      </output>
      <button
        type="button"
        onClick={() =>
          void signIn({
            email: "student.seed@grc.test",
            password: "secret",
          }).then(setSignInResult)
        }
      >
        Sign in
      </button>
      <output aria-label="sign-in result">
        {signInResult?.displayName ?? "none"}
      </output>
      <button type="button" onClick={signOut}>
        Sign out
      </button>
    </>
  )
}

function renderProvider(gateway: AuthGateway) {
  return render(
    <AuthProvider gateway={gateway}>
      <AuthProbe />
    </AuthProvider>,
  )
}

describe("AuthProvider", () => {
  it("restores a session the gateway rebuilds from the stored token", async () => {
    renderProvider(
      createGateway({ restore: () => Promise.resolve(validSession) }),
    )

    await waitFor(() => {
      expect(screen.getByLabelText("auth state")).toHaveTextContent(
        "authenticated:Test Student:persistence-on",
      )
    })
  })

  it("settles as anonymous when there is nothing to restore", async () => {
    renderProvider(createGateway())

    await waitFor(() => {
      expect(screen.getByLabelText("auth state")).toHaveTextContent(
        "anonymous:none:persistence-on",
      )
    })
  })

  it("settles as anonymous when restoring fails outright", async () => {
    renderProvider(
      createGateway({
        restore: () => Promise.reject(new Error("network down")),
      }),
    )

    await waitFor(() => {
      expect(screen.getByLabelText("auth state")).toHaveTextContent(
        "anonymous:none:persistence-on",
      )
    })
  })

  it("keeps a successful sign-in in memory when the token cannot persist", async () => {
    const user = userEvent.setup()
    renderProvider(createGateway({ persistenceAvailable: () => false }))

    await user.click(screen.getByRole("button", { name: "Sign in" }))

    await waitFor(() => {
      expect(screen.getByLabelText("auth state")).toHaveTextContent(
        "authenticated:Test Student:persistence-off",
      )
    })
  })

  it("returns the gateway session from signIn", async () => {
    const user = userEvent.setup()
    renderProvider(createGateway())

    await user.click(screen.getByRole("button", { name: "Sign in" }))

    await waitFor(() => {
      expect(screen.getByLabelText("sign-in result")).toHaveTextContent(
        "Test Student",
      )
    })
  })

  it("clears the session on sign-out and revokes it server-side", async () => {
    const signOut = vi.fn(() => Promise.resolve())
    const user = userEvent.setup()
    renderProvider(
      createGateway({
        restore: () => Promise.resolve(validSession),
        signOut,
      }),
    )

    await screen.findByText(/authenticated:Test Student/)
    await user.click(screen.getByRole("button", { name: "Sign out" }))

    expect(screen.getByLabelText("auth state")).toHaveTextContent(
      "anonymous:none:persistence-on",
    )
    expect(signOut).toHaveBeenCalled()
  })

  it("wipes every saved form draft on sign-out, so the next person starts clean", async () => {
    browserFormDraftStore.write("1", "admission.create-account.v1", {
      first_name: "Ana",
    })
    browserFormDraftStore.write("2", "admission.create-account.v1", {
      first_name: "Ben",
    })
    const user = userEvent.setup()
    renderProvider(
      createGateway({ restore: () => Promise.resolve(validSession) }),
    )

    await screen.findByText(/authenticated:Test Student/)
    // A reload with a live session keeps what was typed…
    expect(
      browserFormDraftStore.read("1", "admission.create-account.v1"),
    ).not.toBeNull()
    await user.click(screen.getByRole("button", { name: "Sign out" }))

    expect(
      browserFormDraftStore.read("1", "admission.create-account.v1"),
    ).toBeNull()
    expect(
      browserFormDraftStore.read("2", "admission.create-account.v1"),
    ).toBeNull()
  })

  it("keeps saved form drafts when the session simply ran out, so signing in again brings them back", async () => {
    browserFormDraftStore.write("1", "admission.create-account.v1", {
      first_name: "Ana",
    })

    renderProvider(createGateway({ restore: () => Promise.resolve(null) }))

    await screen.findByText(/anonymous:none/)
    expect(
      browserFormDraftStore.read("1", "admission.create-account.v1"),
    ).toEqual({ first_name: "Ana" })
  })

  it("signs out locally even when the server-side revoke rejects", async () => {
    const user = userEvent.setup()
    renderProvider(
      createGateway({
        restore: () => Promise.resolve(validSession),
        signOut: () => Promise.reject(new Error("revoke failed")),
      }),
    )

    await screen.findByText(/authenticated:Test Student/)
    await user.click(screen.getByRole("button", { name: "Sign out" }))

    expect(screen.getByLabelText("auth state")).toHaveTextContent(
      "anonymous:none:persistence-on",
    )
  })

  describe("unauthorized handler", () => {
    const fetchMock = vi.fn<typeof fetch>()

    afterEach(() => {
      vi.unstubAllGlobals()
    })

    it("clears the session, but not a half-typed form, when an authenticated request 401s", async () => {
      const clearSession = vi.fn()
      renderProvider(
        createGateway({
          restore: () => Promise.resolve(validSession),
          clearSession,
        }),
      )

      await screen.findByText(/authenticated:Test Student/)
      browserFormDraftStore.write("1", "admission.create-account.v1", {
        first_name: "Ana",
      })

      vi.stubGlobal("fetch", fetchMock)
      fetchMock.mockResolvedValue(
        new Response(
          JSON.stringify({
            error: {
              code: "UNAUTHENTICATED",
              message: "Authentication is required.",
              errors: {},
              request_id: "req-3",
            },
          }),
          { status: 401 },
        ),
      )

      await expect(
        getAuthenticatedJson("/some-protected-resource"),
      ).rejects.toMatchObject({ status: 401 })

      expect(clearSession).toHaveBeenCalled()
      await waitFor(() => {
        expect(screen.getByLabelText("auth state")).toHaveTextContent(
          "anonymous:none:persistence-on",
        )
      })
      // An expired token is not a sign-out: the half-typed form is the last thing to throw away.
      expect(
        browserFormDraftStore.read("1", "admission.create-account.v1"),
      ).toEqual({ first_name: "Ana" })
    })
  })
})
