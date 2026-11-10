import { QueryClient, QueryClientProvider } from "@tanstack/react-query"
import { renderHook, waitFor } from "@testing-library/react"
import type { ReactNode } from "react"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { AuthContext } from "@/features/auth/auth-context-value"
import type { AuthSession } from "@/features/auth/auth-types"
import {
  QueueKioskCredentialMutationCancelledError,
  queueKioskCredentialQueryKey,
  useQueueKioskCredentialMutation,
  useQueueKioskCredentialQuery,
} from "@/features/hooks/use-queue-kiosk-credential"

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

function createWrapper(queryClient: QueryClient, session: AuthSession | null) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        <AuthContext.Provider
          value={{
            session,
            signIn: () => Promise.resolve(accountingSession),
            verifyLoginOtp: () =>
              Promise.reject(new Error("verifyLoginOtp: not implemented in stub")),
            resendLoginOtp: () =>
              Promise.reject(new Error("resendLoginOtp: not implemented in stub")),
            signInWithGoogle: () =>
              Promise.reject(new Error("signInWithGoogle: not implemented in stub")),
            signOut: () => undefined,
    replaceSession: () => undefined,
            status: session ? "authenticated" : "anonymous",
            storageAvailable: true,
          }}
        >
          {children}
        </AuthContext.Provider>
      </QueryClientProvider>
    )
  }
}

describe("useQueueKioskCredential", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("fetches only for the viewer-scoped Accounting query and removes it on unmount", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: credential })),
    )
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })
    const wrapper = createWrapper(queryClient, accountingSession)
    const { result, unmount } = renderHook(
      () => useQueueKioskCredentialQuery(),
      {
        wrapper,
      },
    )

    await waitFor(() => expect(result.current.data).toEqual(credential))
    expect(
      queryClient.getQueryData(queueKioskCredentialQueryKey("12")),
    ).toEqual(credential)

    unmount()

    expect(
      queryClient.getQueryData(queueKioskCredentialQueryKey("12")),
    ).toBeUndefined()
  })

  it("does not fetch for a non-Accounting viewer", async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })
    const wrapper = createWrapper(queryClient, {
      ...accountingSession,
      role: "student",
    })

    renderHook(() => useQueueKioskCredentialQuery(), { wrapper })

    await Promise.resolve()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("replaces the viewer cache after a password rotation", async () => {
    const rotated = { ...credential, password: "rotated-password" }
    fetchMock
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: credential })))
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: rotated })))
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })
    const wrapper = createWrapper(queryClient, accountingSession)
    const { result } = renderHook(
      () => ({
        query: useQueueKioskCredentialQuery(),
        mutation: useQueueKioskCredentialMutation(),
      }),
      { wrapper },
    )

    await waitFor(() => expect(result.current.query.data).toEqual(credential))
    await result.current.mutation.mutateAsync({ password: "rotated-password" })

    expect(
      queryClient.getQueryData(queueKioskCredentialQueryKey("12")),
    ).toEqual(rotated)
  })

  it("does not restore a credential cache after a pending rotation resolves post-unmount", async () => {
    let resolveRotation: ((response: Response) => void) | undefined
    fetchMock
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: credential })))
      .mockImplementationOnce(
        () =>
          new Promise((resolve) => {
            resolveRotation = resolve
          }),
      )
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })
    const wrapper = createWrapper(queryClient, accountingSession)
    const { result, unmount } = renderHook(
      () => ({
        query: useQueueKioskCredentialQuery(),
        mutation: useQueueKioskCredentialMutation(),
      }),
      { wrapper },
    )

    await waitFor(() => expect(result.current.query.data).toEqual(credential))
    const rotation = result.current.mutation.mutateAsync({
      password: "rotated-password",
    })
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2))

    unmount()
    expect(fetchMock.mock.calls[1]?.[1]?.signal?.aborted).toBe(true)
    resolveRotation?.(
      new Response(
        JSON.stringify({
          data: { ...credential, password: "rotated-password" },
        }),
      ),
    )
    await expect(rotation).rejects.toBeInstanceOf(
      QueueKioskCredentialMutationCancelledError,
    )

    expect(
      queryClient.getQueryCache().find({
        queryKey: queueKioskCredentialQueryKey("12"),
        exact: true,
      }),
    ).toBeUndefined()
  })

  it("does not retain either viewer cache when the active session changes during rotation", async () => {
    let resolveRotation: ((response: Response) => void) | undefined
    let activeSession: AuthSession = accountingSession
    fetchMock
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: credential })))
      .mockImplementationOnce(
        () =>
          new Promise((resolve) => {
            resolveRotation = resolve
          }),
      )
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })
    const wrapper = ({ children }: { children: ReactNode }) => (
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
    const { result, rerender } = renderHook(
      () => ({
        query: useQueueKioskCredentialQuery(),
        mutation: useQueueKioskCredentialMutation(),
      }),
      { wrapper },
    )

    await waitFor(() => expect(result.current.query.data).toEqual(credential))
    const rotation = result.current.mutation.mutateAsync({
      password: "rotated-password",
    })
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2))

    activeSession = {
      ...accountingSession,
      userId: "88",
      role: "student",
    }
    rerender()
    expect(fetchMock.mock.calls[1]?.[1]?.signal?.aborted).toBe(true)
    resolveRotation?.(
      new Response(
        JSON.stringify({
          data: { ...credential, password: "rotated-password" },
        }),
      ),
    )
    await expect(rotation).rejects.toBeInstanceOf(
      QueueKioskCredentialMutationCancelledError,
    )

    for (const userId of ["12", "88"]) {
      expect(
        queryClient.getQueryCache().find({
          queryKey: queueKioskCredentialQueryKey(userId),
          exact: true,
        }),
      ).toBeUndefined()
    }
  })
})
