import { screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, describe, expect, it, vi } from "vitest"

import { AuthError } from "@/features/auth/auth-error"
import type { AuthSession } from "@/features/auth/auth-types"
import { LoginOtpRequiredError } from "@/features/auth/login-otp-error"
import { LoginPage } from "@/features/components/pages/login-page"
import { ApiClientError } from "@/features/services/api-client"
import { routerMock } from "@/tests/navigation-mock"
import { createStubGateway, renderWithAuthProvider } from "@/tests/render-app"

vi.mock("next/script", () => ({
  // The real component only needs the load callback to fire; jsdom never
  // actually loads the external script.
  default: ({ onLoad }: { onLoad?: () => void }) => {
    onLoad?.()
    return null
  },
}))

const studentSession: AuthSession = {
  userId: "1",
  displayName: "Test Student",
  role: "student",
  signedInAt: "2026-07-26T12:00:00.000Z",
}

function renderLogin(route = "/login", gateway = createStubGateway()) {
  return renderWithAuthProvider(<LoginPage />, { route, gateway })
}

async function enterCredentials(
  user: ReturnType<typeof userEvent.setup>,
  email = "student.seed@grc.test",
  password = "a-correct-password",
) {
  await user.type(await screen.findByLabelText("Email address"), email)
  await user.type(screen.getByLabelText("Password"), password)
}

describe("LoginPage", () => {
  it("renders an accessible institutional form with a forgot-password link and no unimplemented account actions", async () => {
    renderLogin()

    expect(
      await screen.findByRole("heading", { name: "Sign in to your portal" }),
    ).toBeInTheDocument()
    expect(screen.getByLabelText("Email address")).toHaveAttribute(
      "autocomplete",
      "username",
    )
    expect(screen.getByLabelText("Password")).toHaveAttribute(
      "autocomplete",
      "current-password",
    )
    expect(
      screen.getByText("docs/testing/SEEDED_IDENTITIES.md"),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("link", { name: /forgot password/i }),
    ).toHaveAttribute("href", "/forgot-password")
    // It sits underneath the password field (and above Sign in), not beside its label.
    const forgot = screen.getByRole("link", { name: /forgot password/i })
    expect(
      screen
        .getByLabelText("Password")
        .compareDocumentPosition(forgot) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy()
    expect(
      forgot.compareDocumentPosition(
        screen.getByRole("button", { name: "Sign in" }),
      ) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy()
    expect(
      screen.queryByText(/register|create account/i),
    ).not.toBeInTheDocument()
  })

  it("carries no demo-credential disclaimer now that demo mode is gone", async () => {
    renderLogin()

    await screen.findByRole("heading", { name: "Sign in to your portal" })
    expect(
      screen.queryByText("Interface demonstration—not real authentication"),
    ).not.toBeInTheDocument()
    expect(
      screen.queryByText("docs/testing/DEMO_CREDENTIALS.md"),
    ).not.toBeInTheDocument()
    expect(screen.getByLabelText("Email address")).toBeEnabled()
    expect(screen.getByRole("button", { name: "Sign in" })).toBeEnabled()
  })

  it("focuses a summary and identifies invalid fields", async () => {
    const user = userEvent.setup()
    renderLogin()

    await user.click(await screen.findByRole("button", { name: "Sign in" }))

    const summary = await screen.findByRole("alert", { name: "Sign-in errors" })
    expect(summary).toHaveFocus()
    expect(screen.getByLabelText("Email address")).toHaveAttribute(
      "aria-invalid",
      "true",
    )
    expect(screen.getByLabelText("Password")).toHaveAttribute(
      "aria-invalid",
      "true",
    )
  })

  it("uses an action-labeled password visibility control", async () => {
    const user = userEvent.setup()
    renderLogin()

    const password = await screen.findByLabelText("Password")
    expect(password).toHaveAttribute("type", "password")

    await user.click(screen.getByRole("button", { name: "Show password" }))
    expect(password).toHaveAttribute("type", "text")

    await user.click(screen.getByRole("button", { name: "Hide password" }))
    expect(password).toHaveAttribute("type", "password")
  })

  it("shows one generic credential error, retains email, and clears password", async () => {
    const user = userEvent.setup()
    renderLogin(
      "/login",
      createStubGateway({
        signIn: () => Promise.reject(new AuthError("INVALID_CREDENTIALS")),
      }),
    )
    await enterCredentials(user, "student.seed@grc.test", "incorrect-password")

    await user.click(screen.getByRole("button", { name: "Sign in" }))

    expect(
      await screen.findByText(
        "The email or password you entered was not recognized.",
      ),
    ).toBeInTheDocument()
    expect(screen.getByLabelText("Email address")).toHaveValue(
      "student.seed@grc.test",
    )
    expect(screen.getByLabelText("Password")).toHaveValue("")
    expect(document.body).not.toHaveTextContent("incorrect-password")
  })

  it("directs an authenticated kiosk identity to the queue device portal", async () => {
    const user = userEvent.setup()
    renderLogin(
      "/login",
      createStubGateway({
        signIn: () =>
          Promise.reject(new AuthError("QUEUE_KIOSK_REQUIRES_DEVICE_PORTAL")),
      }),
    )
    await enterCredentials(user)

    await user.click(screen.getByRole("button", { name: "Sign in" }))

    expect(
      await screen.findByRole("link", { name: /queue kiosk/i }),
    ).toHaveAttribute("href", "/queue")
    expect(
      screen.queryByText(
        "The email or password you entered was not recognized.",
      ),
    ).not.toBeInTheDocument()
  })

  it("locks the form and shows a countdown after a rate-limit response", async () => {
    const user = userEvent.setup()
    renderLogin(
      "/login",
      createStubGateway({
        signIn: () =>
          Promise.reject(
            new ApiClientError({
              kind: "http",
              message: "Too many requests. Please retry later.",
              status: 429,
              retryAfterSeconds: 45,
            }),
          ),
      }),
    )
    await enterCredentials(user)

    await user.click(screen.getByRole("button", { name: "Sign in" }))

    expect(await screen.findByText("Too many attempts.")).toBeInTheDocument()
    expect(
      screen.getByRole("button", { name: "Try again in 45s" }),
    ).toBeDisabled()
    expect(screen.getByLabelText("Email address")).toBeDisabled()
    expect(screen.getByLabelText("Password")).toBeDisabled()
    expect(
      screen.queryByText(
        "The email or password you entered was not recognized.",
      ),
    ).not.toBeInTheDocument()
  })

  it("reports an unexpected failure with the same generic message", async () => {
    const user = userEvent.setup()
    renderLogin(
      "/login",
      createStubGateway({
        signIn: () => Promise.reject(new Error("gateway exploded")),
      }),
    )
    await enterCredentials(user)

    await user.click(screen.getByRole("button", { name: "Sign in" }))

    expect(
      await screen.findByText(
        "The email or password you entered was not recognized.",
      ),
    ).toBeInTheDocument()
    expect(document.body).not.toHaveTextContent("gateway exploded")
  })

  it("normalizes credentials and honors a safe internal return path", async () => {
    const user = userEvent.setup()
    let received: { email: string; password: string } | null = null
    renderLogin(
      "/login?returnTo=%2Fportal%2Fenrollment",
      createStubGateway({
        signIn: (credentials) => {
          received = credentials
          return Promise.resolve(studentSession)
        },
      }),
    )
    await enterCredentials(user, "  STUDENT.SEED@GRC.TEST  ", "a-password")

    await user.click(screen.getByRole("button", { name: "Sign in" }))

    await waitFor(() => {
      expect(routerMock.replace).toHaveBeenCalledWith("/portal/enrollment")
    })
    expect(received).toEqual({
      email: "student.seed@grc.test",
      password: "a-password",
    })
  })

  it("falls back to the portal overview for an unsafe return target", async () => {
    const user = userEvent.setup()
    renderLogin(
      "/login?returnTo=https%3A%2F%2Fevil.example%2Fportal",
      createStubGateway({ signIn: () => Promise.resolve(studentSession) }),
    )
    await enterCredentials(user)

    await user.click(screen.getByRole("button", { name: "Sign in" }))

    await waitFor(() => {
      expect(routerMock.replace).toHaveBeenCalledWith("/portal")
    })
    expect(routerMock.replace).not.toHaveBeenCalledWith(
      expect.stringContaining("evil.example"),
    )
  })

  it("announces and disables the form while sign-in is pending", async () => {
    let resolveSignIn: (session: AuthSession) => void = () => undefined
    const user = userEvent.setup()
    renderLogin(
      "/login",
      createStubGateway({
        signIn: () =>
          new Promise((resolve) => {
            resolveSignIn = resolve
          }),
      }),
    )
    await enterCredentials(user)

    await user.click(screen.getByRole("button", { name: "Sign in" }))

    expect(screen.getByRole("button", { name: "Signing in…" })).toBeDisabled()
    resolveSignIn(studentSession)

    await waitFor(() => {
      expect(routerMock.replace).toHaveBeenCalledWith("/portal")
    })
  })

  describe("login-OTP challenge", () => {
    function otpChallenge() {
      return new LoginOtpRequiredError({
        challengeToken: "challenge-token-a",
        email: "student.seed@grc.test",
        expiresAt: "2026-07-26T12:10:00.000Z",
      })
    }

    it("shows the code step instead of a generic credential error", async () => {
      const user = userEvent.setup()
      renderLogin(
        "/login",
        createStubGateway({ signIn: () => Promise.reject(otpChallenge()) }),
      )
      await enterCredentials(user)

      await user.click(screen.getByRole("button", { name: "Sign in" }))

      expect(
        await screen.findByRole("heading", { name: "Check your email" }),
      ).toBeInTheDocument()
      expect(screen.getByText("student.seed@grc.test")).toBeInTheDocument()
      expect(screen.getByLabelText("Verification code")).toBeInTheDocument()
      expect(
        screen.queryByText(
          "The email or password you entered was not recognized.",
        ),
      ).not.toBeInTheDocument()
    })

    it("submits the code and completes sign-in", async () => {
      const user = userEvent.setup()
      let verifiedWith: { challengeToken: string; code: string } | null = null
      renderLogin(
        "/login?returnTo=%2Fportal%2Fenrollment",
        createStubGateway({
          signIn: () => Promise.reject(otpChallenge()),
          verifyLoginOtp: (challengeToken, code) => {
            verifiedWith = { challengeToken, code }
            return Promise.resolve(studentSession)
          },
        }),
      )
      await enterCredentials(user)
      await user.click(screen.getByRole("button", { name: "Sign in" }))
      await screen.findByRole("heading", { name: "Check your email" })

      await user.type(screen.getByLabelText("Verification code"), "123456")
      await user.click(
        screen.getByRole("button", { name: "Verify and sign in" }),
      )

      await waitFor(() => {
        expect(routerMock.replace).toHaveBeenCalledWith("/portal/enrollment")
      })
      expect(verifiedWith).toEqual({
        challengeToken: "challenge-token-a",
        code: "123456",
      })
    })

    it("shows a field error for a rejected code without leaving the code step", async () => {
      const user = userEvent.setup()
      renderLogin(
        "/login",
        createStubGateway({
          signIn: () => Promise.reject(otpChallenge()),
          verifyLoginOtp: () =>
            Promise.reject(
              new ApiClientError({
                kind: "http",
                message: "The submitted data is invalid.",
                status: 422,
                fieldErrors: {
                  code: ["This verification code is invalid or expired."],
                },
              }),
            ),
        }),
      )
      await enterCredentials(user)
      await user.click(screen.getByRole("button", { name: "Sign in" }))
      await screen.findByRole("heading", { name: "Check your email" })

      await user.type(screen.getByLabelText("Verification code"), "000000")
      await user.click(
        screen.getByRole("button", { name: "Verify and sign in" }),
      )

      expect(
        await screen.findByText(
          "This verification code is invalid or expired.",
        ),
      ).toBeInTheDocument()
      expect(
        screen.getByRole("heading", { name: "Check your email" }),
      ).toBeInTheDocument()
      expect(routerMock.replace).not.toHaveBeenCalled()
    })

    it("resend swaps the token transparently and lets a code from the new token verify", async () => {
      const user = userEvent.setup()
      let verifiedToken: string | null = null
      renderLogin(
        "/login",
        createStubGateway({
          signIn: () => Promise.reject(otpChallenge()),
          resendLoginOtp: () =>
            Promise.resolve({
              challengeToken: "challenge-token-b",
              email: "student.seed@grc.test",
              expiresAt: "2026-07-26T12:20:00.000Z",
            }),
          verifyLoginOtp: (challengeToken) => {
            verifiedToken = challengeToken
            return Promise.resolve(studentSession)
          },
        }),
      )
      await enterCredentials(user)
      await user.click(screen.getByRole("button", { name: "Sign in" }))
      await screen.findByRole("heading", { name: "Check your email" })

      await user.click(screen.getByRole("button", { name: "Resend code" }))
      expect(
        await screen.findByText("A new code was sent to your email."),
      ).toBeInTheDocument()

      await user.type(screen.getByLabelText("Verification code"), "654321")
      await user.click(
        screen.getByRole("button", { name: "Verify and sign in" }),
      )

      await waitFor(() => {
        expect(routerMock.replace).toHaveBeenCalledWith("/portal")
      })
      expect(verifiedToken).toBe("challenge-token-b")
    })

    it("returns to the credentials form via \"Use a different account\"", async () => {
      const user = userEvent.setup()
      renderLogin(
        "/login",
        createStubGateway({ signIn: () => Promise.reject(otpChallenge()) }),
      )
      await enterCredentials(user)
      await user.click(screen.getByRole("button", { name: "Sign in" }))
      await screen.findByRole("heading", { name: "Check your email" })

      await user.click(
        screen.getByRole("button", { name: "Use a different account" }),
      )

      expect(
        await screen.findByRole("heading", { name: "Sign in to your portal" }),
      ).toBeInTheDocument()
    })
  })

  describe("Google sign-in", () => {
    const originalClientId = process.env.NEXT_PUBLIC_GOOGLE_CLIENT_ID
    const CLIENT_ID = "test-client-id.apps.googleusercontent.com"

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

    afterEach(() => {
      process.env.NEXT_PUBLIC_GOOGLE_CLIENT_ID = originalClientId
      delete (window as { google?: unknown }).google
    })

    it("shows the contact-Admission message when the Google email matches no account", async () => {
      process.env.NEXT_PUBLIC_GOOGLE_CLIENT_ID = CLIENT_ID
      const { initialize } = stubGoogleIdentityServices()
      renderLogin(
        "/login",
        createStubGateway({
          signInWithGoogle: () =>
            Promise.reject(new AuthError("GOOGLE_ACCOUNT_NOT_FOUND")),
        }),
      )
      await screen.findByRole("heading", { name: "Sign in to your portal" })

      const { callback } = initialize.mock.calls[0]?.[0] as {
        callback: (response: { credential: string }) => void
      }
      callback({ credential: "fake-google-credential" })

      expect(
        await screen.findByText(/contact Admission/i),
      ).toBeInTheDocument()
      expect(routerMock.replace).not.toHaveBeenCalled()
    })
  })

  describe("on a phone", () => {
    // The two panels stack at (max-width: 45rem), so the hero fills the first
    // screen and the credentials sit a screen below (stakeholder Doc 13).
    function stubViewport(isPhone: boolean) {
      vi.spyOn(window, "matchMedia").mockImplementation(
        (query: string) =>
          ({
            matches: query.includes("max-width: 45rem")
              ? isPhone
              : query.includes("prefers-reduced-motion"),
            media: query,
            onchange: null,
            addListener: () => undefined,
            removeListener: () => undefined,
            addEventListener: () => undefined,
            removeEventListener: () => undefined,
            dispatchEvent: () => false,
          }) as MediaQueryList,
      )
    }

    afterEach(() => {
      vi.restoreAllMocks()
    })

    it("scrolls the sign-in form into view as soon as the page opens", async () => {
      stubViewport(true)
      const scrollIntoView = vi.spyOn(
        window.HTMLElement.prototype,
        "scrollIntoView",
      )

      renderLogin()
      await screen.findByRole("heading", { name: "Sign in to your portal" })

      await waitFor(() => expect(scrollIntoView).toHaveBeenCalledTimes(1))
      expect(scrollIntoView).toHaveBeenCalledWith({ block: "start" })
      // It scrolls the form's panel, which contains the credentials.
      const scrolled = scrollIntoView.mock.contexts[0] as HTMLElement
      expect(scrolled).toContainElement(screen.getByLabelText("Email address"))
      expect(scrolled).toContainElement(screen.getByLabelText("Password"))
    })

    it("only scrolls; it never steals focus or opens the keyboard", async () => {
      stubViewport(true)

      renderLogin()
      await screen.findByRole("heading", { name: "Sign in to your portal" })

      expect(screen.getByLabelText("Email address")).not.toHaveFocus()
      expect(screen.getByLabelText("Password")).not.toHaveFocus()
    })

    it("does not scroll on a wide screen where both panels are already visible", async () => {
      stubViewport(false)
      const scrollIntoView = vi.spyOn(
        window.HTMLElement.prototype,
        "scrollIntoView",
      )

      renderLogin()
      await screen.findByRole("heading", { name: "Sign in to your portal" })

      expect(scrollIntoView).not.toHaveBeenCalled()
    })
  })
})
