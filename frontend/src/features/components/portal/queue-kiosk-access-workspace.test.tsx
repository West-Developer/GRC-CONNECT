import { render, screen, waitFor, within } from "@testing-library/react"
import { QueryClient, QueryClientProvider } from "@tanstack/react-query"
import type { ReactNode } from "react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { QueueKioskAccessWorkspace } from "@/features/components/portal/queue-kiosk-access-workspace"
import { AuthContext } from "@/features/auth/auth-context-value"
import type { AuthSession } from "@/features/auth/auth-types"
import { renderWithSession } from "@/tests/render-app"

const credential = {
  type: "queue_kiosk_credential",
  email: "queue.kiosk@grc.edu.ph",
  password: "temporary-password",
} as const

const accountingSession = {
  userId: "12",
  displayName: "Accounting Staff",
  role: "accounting_staff",
  signedInAt: "2026-08-23T00:00:00Z",
} as const

describe("QueueKioskAccessWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("shows a loading status before the credential is available", () => {
    fetchMock.mockReturnValue(new Promise(() => undefined))
    renderWithSession(<QueueKioskAccessWorkspace />, {
      session: accountingSession,
    })

    expect(
      screen.getByRole("status", { name: "Loading kiosk access…" }),
    ).toBeInTheDocument()
  })

  it("shows the API error with a retry action", async () => {
    const user = userEvent.setup()
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          error: {
            message: "Access unavailable",
            code: "ACCESS_UNAVAILABLE",
            errors: {},
            request_id: "req-1",
          },
        }),
        { status: 400 },
      ),
    )
    renderWithSession(<QueueKioskAccessWorkspace />, {
      session: accountingSession,
    })

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "Access unavailable",
    )
    await user.click(screen.getByRole("button", { name: "Try again" }))
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it("keeps the password hidden by default and allows a guarded reveal", async () => {
    const user = userEvent.setup()
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: credential })),
    )
    renderWithSession(<QueueKioskAccessWorkspace />, {
      session: accountingSession,
    })

    const password = await screen.findByLabelText("Kiosk password")
    expect(password).toHaveAttribute("type", "password")
    expect(
      screen.getByText("Password is hidden by default."),
    ).toBeInTheDocument()

    await user.click(screen.getByRole("button", { name: "Reveal password" }))
    expect(password).toHaveAttribute("type", "text")
    expect(password).toHaveValue("temporary-password")

    await user.click(screen.getByRole("button", { name: "Hide password" }))
    expect(password).toHaveAttribute("type", "password")
  })

  it("prominently warns when the seeded default password is still active", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({ data: { ...credential, password: "password" } }),
      ),
    )
    renderWithSession(<QueueKioskAccessWorkspace />, {
      session: accountingSession,
    })

    await screen.findByLabelText("Kiosk password")
    const warning = screen.getByRole("alert")
    expect(warning).toHaveTextContent("Rotate the default kiosk password now")
    expect(warning).toHaveTextContent("immediately")
    expect(warning).not.toHaveTextContent("queue.kiosk@grc.edu.ph")
  })

  it("always forbids reusing the shared credential for a personal account", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: credential })),
    )
    renderWithSession(<QueueKioskAccessWorkspace />, {
      session: accountingSession,
    })

    await screen.findByLabelText("Kiosk password")
    expect(
      screen.getByText(
        /This shared-device credential must never be reused for a personal account\./,
      ),
    ).toBeInTheDocument()
    expect(
      screen.queryByText("Rotate the default kiosk password now"),
    ).not.toBeInTheDocument()
  })

  it("rejects a client password shorter than eight characters", async () => {
    const user = userEvent.setup()
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: credential })),
    )
    renderWithSession(<QueueKioskAccessWorkspace />, {
      session: accountingSession,
    })

    await screen.findByLabelText("Kiosk password")
    await user.type(screen.getByLabelText("New kiosk password"), "short")
    await user.click(screen.getByRole("button", { name: "Rotate password" }))

    expect(
      await screen.findByText("Use at least 8 characters."),
    ).toBeInTheDocument()
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it("warns that active kiosks sign out and makes one rotation request while pending", async () => {
    const user = userEvent.setup()
    let resolveRotation: ((response: Response) => void) | undefined
    fetchMock
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: credential })))
      .mockImplementationOnce(
        () =>
          new Promise((resolve) => {
            resolveRotation = resolve
          }),
      )
    renderWithSession(<QueueKioskAccessWorkspace />, {
      session: accountingSession,
    })

    await screen.findByLabelText("Kiosk password")
    await user.type(
      screen.getByLabelText("New kiosk password"),
      "rotated-password",
    )
    await user.click(screen.getByRole("button", { name: "Rotate password" }))

    expect(screen.getByRole("alertdialog")).toHaveTextContent(
      "Active kiosks will sign out",
    )
    const confirm = screen.getByRole("button", { name: "Confirm rotation" })
    await user.click(confirm)
    await user.click(confirm)
    expect(fetchMock).toHaveBeenCalledTimes(2)

    resolveRotation?.(new Response(JSON.stringify({ data: credential })))
    expect(
      await screen.findByText("Kiosk password rotated."),
    ).toBeInTheDocument()
  })

  it("sends exactly one PUT when confirmation is activated twice before pending state renders", async () => {
    const user = userEvent.setup()
    fetchMock
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: credential })))
      .mockReturnValueOnce(new Promise(() => undefined))
    renderWithSession(<QueueKioskAccessWorkspace />, {
      session: accountingSession,
    })

    await screen.findByLabelText("Kiosk password")
    await user.type(
      screen.getByLabelText("New kiosk password"),
      "rotated-password",
    )
    await user.click(screen.getByRole("button", { name: "Rotate password" }))
    const confirm = screen.getByRole("button", { name: "Confirm rotation" })

    confirm.click()
    confirm.click()

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2))
  })

  it("does not continue viewer A's completed rotation in viewer B's workspace", async () => {
    const user = userEvent.setup()
    let resolveRotation: ((response: Response) => void) | undefined
    let activeSession: AuthSession = accountingSession
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })
    fetchMock
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: credential })))
      .mockImplementationOnce(
        () =>
          new Promise((resolve) => {
            resolveRotation = resolve
          }),
      )
      .mockResolvedValueOnce(
        new Response(
          JSON.stringify({
            data: { ...credential, email: "queue-b@grc.edu.ph" },
          }),
        ),
      )
    const Wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={queryClient}>
        <AuthContext.Provider
          value={{
            session: activeSession,
            signIn: () => Promise.resolve(accountingSession),
            verifyLoginOtp: () =>
              Promise.reject(new Error("verifyLoginOtp: not implemented in stub")),
            resendLoginOtp: () =>
              Promise.reject(new Error("resendLoginOtp: not implemented in stub")),
            signInWithGoogle: () =>
              Promise.reject(new Error("signInWithGoogle: not implemented in stub")),
            signOut: () => undefined,
    replaceSession: () => undefined,
            status: "authenticated",
            storageAvailable: true,
          }}
        >
          {children}
        </AuthContext.Provider>
      </QueryClientProvider>
    )
    const view = render(
      <Wrapper>
        <QueueKioskAccessWorkspace />
      </Wrapper>,
    )

    await screen.findByLabelText("Kiosk password")
    await user.type(
      screen.getByLabelText("New kiosk password"),
      "rotated-password",
    )
    await user.click(screen.getByRole("button", { name: "Rotate password" }))
    await user.click(screen.getByRole("button", { name: "Confirm rotation" }))
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2))

    activeSession = { ...accountingSession, userId: "88" }
    view.rerender(
      <Wrapper>
        <QueueKioskAccessWorkspace />
      </Wrapper>,
    )
    resolveRotation?.(
      new Response(
        JSON.stringify({
          data: { ...credential, password: "rotated-password" },
        }),
      ),
    )

    await screen.findByDisplayValue("queue-b@grc.edu.ph")
    expect(
      screen.queryByText("Kiosk password rotated."),
    ).not.toBeInTheDocument()
    expect(screen.queryByRole("alertdialog")).not.toBeInTheDocument()
    expect(screen.getByLabelText("New kiosk password")).toHaveValue("")
  })

  it("does not render viewer A's dialog, error, or status during viewer B's rerender", async () => {
    const user = userEvent.setup()
    let activeSession: AuthSession = accountingSession
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })
    fetchMock
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: credential })))
      .mockResolvedValueOnce(
        new Response(
          JSON.stringify({
            error: {
              message: "Rotation unavailable",
              code: "ROTATION_UNAVAILABLE",
              errors: {},
              request_id: "req-transition-1",
            },
          }),
          { status: 400 },
        ),
      )
      .mockResolvedValueOnce(
        new Response(
          JSON.stringify({
            data: { ...credential, email: "queue-b@grc.edu.ph" },
          }),
        ),
      )
    const Wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={queryClient}>
        <AuthContext.Provider
          value={{
            session: activeSession,
            signIn: () => Promise.resolve(accountingSession),
            verifyLoginOtp: () =>
              Promise.reject(new Error("verifyLoginOtp: not implemented in stub")),
            resendLoginOtp: () =>
              Promise.reject(new Error("resendLoginOtp: not implemented in stub")),
            signInWithGoogle: () =>
              Promise.reject(new Error("signInWithGoogle: not implemented in stub")),
            signOut: () => undefined,
    replaceSession: () => undefined,
            status: "authenticated",
            storageAvailable: true,
          }}
        >
          {children}
        </AuthContext.Provider>
      </QueryClientProvider>
    )
    const view = render(
      <Wrapper>
        <QueueKioskAccessWorkspace />
      </Wrapper>,
    )

    await screen.findByLabelText("Kiosk password")
    await user.type(
      screen.getByLabelText("New kiosk password"),
      "rotated-password",
    )
    await user.click(screen.getByRole("button", { name: "Rotate password" }))
    await user.click(screen.getByRole("button", { name: "Confirm rotation" }))
    expect(await screen.findByRole("alertdialog")).toHaveTextContent(
      "The kiosk password could not be rotated.",
    )

    activeSession = { ...accountingSession, userId: "88" }
    view.rerender(
      <Wrapper>
        <QueueKioskAccessWorkspace />
      </Wrapper>,
    )

    expect(screen.queryByRole("alertdialog")).not.toBeInTheDocument()
    expect(
      screen.queryByText("The kiosk password could not be rotated."),
    ).not.toBeInTheDocument()
    expect(
      screen.queryByText("Kiosk password rotated."),
    ).not.toBeInTheDocument()
  })

  it("starts viewer B with the credential masked after viewer A revealed it", async () => {
    const user = userEvent.setup()
    let activeSession: AuthSession = accountingSession
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })
    fetchMock
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: credential })))
      .mockResolvedValueOnce(
        new Response(
          JSON.stringify({
            data: { ...credential, email: "queue-b@grc.edu.ph" },
          }),
        ),
      )
    const Wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={queryClient}>
        <AuthContext.Provider
          value={{
            session: activeSession,
            signIn: () => Promise.resolve(accountingSession),
            verifyLoginOtp: () =>
              Promise.reject(new Error("verifyLoginOtp: not implemented in stub")),
            resendLoginOtp: () =>
              Promise.reject(new Error("resendLoginOtp: not implemented in stub")),
            signInWithGoogle: () =>
              Promise.reject(new Error("signInWithGoogle: not implemented in stub")),
            signOut: () => undefined,
    replaceSession: () => undefined,
            status: "authenticated",
            storageAvailable: true,
          }}
        >
          {children}
        </AuthContext.Provider>
      </QueryClientProvider>
    )
    const view = render(
      <Wrapper>
        <QueueKioskAccessWorkspace />
      </Wrapper>,
    )

    const password = await screen.findByLabelText("Kiosk password")
    await user.click(screen.getByRole("button", { name: "Reveal password" }))
    expect(password).toHaveAttribute("type", "text")

    activeSession = { ...accountingSession, userId: "88" }
    view.rerender(
      <Wrapper>
        <QueueKioskAccessWorkspace />
      </Wrapper>,
    )

    const viewerBPassword =
      await screen.findByDisplayValue("temporary-password")
    expect(viewerBPassword).toHaveAttribute("type", "password")
    expect(
      screen.getByRole("button", { name: "Reveal password" }),
    ).toBeInTheDocument()
  })

  it("keeps rotation failure feedback visible and retryable in the confirmation dialog", async () => {
    const user = userEvent.setup()
    fetchMock
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: credential })))
      .mockResolvedValueOnce(
        new Response(
          JSON.stringify({
            error: {
              message: "Rotation unavailable",
              code: "ROTATION_UNAVAILABLE",
              errors: {},
              request_id: "req-rotation-1",
            },
          }),
          { status: 400 },
        ),
      )
    renderWithSession(<QueueKioskAccessWorkspace />, {
      session: accountingSession,
    })

    await screen.findByLabelText("Kiosk password")
    await user.type(
      screen.getByLabelText("New kiosk password"),
      "rotated-password",
    )
    await user.click(screen.getByRole("button", { name: "Rotate password" }))
    const dialog = screen.getByRole("alertdialog")
    const confirm = within(dialog).getByRole("button", {
      name: "Confirm rotation",
    })
    await user.click(confirm)

    expect(await within(dialog).findByRole("alert")).toHaveTextContent(
      "The kiosk password could not be rotated.",
    )
    expect(
      within(dialog).getByRole("button", { name: "Try rotation again" }),
    ).toHaveFocus()
  })

  it("moves focus to the in-dialog retry after a deferred rotation failure", async () => {
    const user = userEvent.setup()
    let rejectRotation: ((error: Error) => void) | undefined
    fetchMock
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: credential })))
      .mockImplementationOnce(
        () =>
          new Promise((_resolve, reject) => {
            rejectRotation = reject
          }),
      )
    renderWithSession(<QueueKioskAccessWorkspace />, {
      session: accountingSession,
    })

    await screen.findByLabelText("Kiosk password")
    await user.type(
      screen.getByLabelText("New kiosk password"),
      "rotated-password",
    )
    await user.click(screen.getByRole("button", { name: "Rotate password" }))
    const dialog = screen.getByRole("alertdialog")
    const confirm = within(dialog).getByRole("button", {
      name: "Confirm rotation",
    })
    await user.click(confirm)
    expect(confirm).toBeDisabled()
    const cancel = within(dialog).getByRole("button", { name: "Cancel" })
    cancel.focus()
    expect(cancel).toHaveFocus()

    rejectRotation?.(new Error("offline"))

    expect(await within(dialog).findByRole("alert")).toHaveTextContent(
      "The kiosk password could not be rotated.",
    )
    const retry = within(dialog).getByRole("button", {
      name: "Try rotation again",
    })
    await waitFor(() => expect(retry).toHaveFocus())
  })

  it("has no detectable accessibility violations after the credential loads", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: credential })),
    )
    const { container } = renderWithSession(<QueueKioskAccessWorkspace />, {
      session: accountingSession,
    })

    await screen.findByLabelText("Kiosk password")
    expect(await axe(container)).toHaveNoViolations()
  })
})
