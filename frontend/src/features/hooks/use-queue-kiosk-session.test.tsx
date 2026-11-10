import { act, renderHook, waitFor } from "@testing-library/react"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import type { KioskTokenStore } from "@/features/kiosk/kiosk-token"
import { useQueueKioskSession } from "@/features/hooks/use-queue-kiosk-session"
import type { AuthenticatedUser } from "@/features/schemas/auth-schema"
import { setUnauthorizedHandler } from "@/features/services/api-client"
import { agreedPrivacyNotice } from "@/tests/privacy-notice-fixture"

const kioskUser = {
  type: "user" as const,
  id: 11,
  name: "Queue Kiosk",
  email: "kiosk@grc.test",
  role: "queue_kiosk" as const,
  role_label: "Queue Kiosk",
  privacy_notice: agreedPrivacyNotice,
  status: "active",
  college: null,
}

const studentUser = {
  type: "user" as const,
  id: 22,
  name: "Student One",
  email: "student@grc.test",
  role: "student" as const,
  role_label: "Student",
  privacy_notice: agreedPrivacyNotice,
  status: "active",
  college: "ccs" as const,
}

function authResponse(token: string, user: AuthenticatedUser = kioskUser) {
  return new Response(
    JSON.stringify({
      data: {
        type: "auth-session",
        token,
        token_type: "Bearer",
        expires_at: null,
        user,
      },
    }),
  )
}

function userResponse(user: AuthenticatedUser = kioskUser) {
  return new Response(JSON.stringify({ data: user }))
}

function errorResponse(status: number) {
  return new Response(
    JSON.stringify({
      error: {
        code: "UNAUTHENTICATED",
        message: "Authentication is required.",
        errors: {},
        request_id: "request-1",
      },
    }),
    { status },
  )
}

function createTokenStore(initial: string | null = null) {
  let value = initial
  return {
    clear: vi.fn(() => {
      value = null
    }),
    read: vi.fn(() => value),
    write: vi.fn((token: string) => {
      value = token
      return true
    }),
  } satisfies KioskTokenStore
}

describe("useQueueKioskSession", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    setUnauthorizedHandler(vi.fn())
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    setUnauthorizedHandler(() => undefined)
  })

  it("restores a valid persisted device token with an isolated bearer", async () => {
    const store = createTokenStore("kiosk-token")
    fetchMock.mockResolvedValue(userResponse())
    const { result } = renderHook(() =>
      useQueueKioskSession({ tokenStore: store }),
    )

    await waitFor(() =>
      expect(result.current.state.status).toBe("student-login"),
    )

    expect(result.current.state).toMatchObject({
      kioskToken: "kiosk-token",
      kioskUser,
    })
    const [, init] = fetchMock.mock.calls[0] ?? []
    expect(init?.headers).toMatchObject({ Authorization: "Bearer kiosk-token" })
  })

  it("clears only kiosk persistence when restore is rejected or belongs to a human", async () => {
    const rejectedStore = createTokenStore("old-kiosk")
    fetchMock.mockResolvedValueOnce(errorResponse(401))
    const rejected = renderHook(() =>
      useQueueKioskSession({ tokenStore: rejectedStore }),
    )
    await waitFor(() =>
      expect(rejected.result.current.state.status).toBe("device-login"),
    )
    expect(rejectedStore.clear).toHaveBeenCalledOnce()

    const wrongRoleStore = createTokenStore("human-token")
    fetchMock
      .mockResolvedValueOnce(userResponse(studentUser))
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
    const wrongRole = renderHook(() =>
      useQueueKioskSession({ tokenStore: wrongRoleStore }),
    )
    await waitFor(() =>
      expect(wrongRole.result.current.state.status).toBe("device-login"),
    )
    expect(wrongRoleStore.clear).toHaveBeenCalledOnce()
    expect(fetchMock.mock.calls[2]?.[1]?.headers).toMatchObject({
      Authorization: "Bearer human-token",
    })
  })

  it("persists only a queue kiosk device login and revokes an issued human token", async () => {
    const store = createTokenStore()
    fetchMock
      .mockResolvedValueOnce(authResponse("device-token"))
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(authResponse("human-token", studentUser))
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
    const { result } = renderHook(() =>
      useQueueKioskSession({ tokenStore: store }),
    )
    await waitFor(() =>
      expect(result.current.state.status).toBe("device-login"),
    )

    await act(async () => {
      await result.current.signInDevice({
        email: "KIOSK@GRC.TEST",
        password: "secret",
      })
    })
    expect(result.current.state.status).toBe("student-login")
    expect(store.write).toHaveBeenCalledWith("device-token")

    await act(async () => {
      result.current.signOutDevice()
      await result.current.signInDevice({
        email: "student@grc.test",
        password: "secret",
      })
    })
    expect(result.current.state).toEqual({
      status: "device-login",
      error: "This account is not authorized for the Queue Kiosk.",
    })
    expect(fetchMock.mock.calls[3]?.[1]?.headers).toMatchObject({
      Authorization: "Bearer human-token",
    })
  })

  it("blocks a device login when persistence fails and revokes its issued token", async () => {
    const store = { ...createTokenStore(), write: vi.fn(() => false) }
    fetchMock
      .mockResolvedValueOnce(authResponse("device-token"))
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
    const { result } = renderHook(() =>
      useQueueKioskSession({ tokenStore: store }),
    )
    await waitFor(() =>
      expect(result.current.state.status).toBe("device-login"),
    )

    await act(async () => {
      await result.current.signInDevice({
        email: "kiosk@grc.test",
        password: "secret",
      })
    })

    expect(result.current.state).toEqual({
      status: "device-login",
      error:
        "This device cannot securely retain its kiosk session. Enable browser storage and try again.",
    })
    expect(fetchMock.mock.calls[1]?.[1]?.headers).toMatchObject({
      Authorization: "Bearer device-token",
    })
  })

  it("keeps Student credentials only in memory and revokes a wrong-role Student login", async () => {
    const store = createTokenStore()
    fetchMock
      .mockResolvedValueOnce(authResponse("device-token"))
      .mockResolvedValueOnce(authResponse("student-token", studentUser))
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(authResponse("staff-token", kioskUser))
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
    const { result } = renderHook(() =>
      useQueueKioskSession({ tokenStore: store }),
    )
    await waitFor(() =>
      expect(result.current.state.status).toBe("device-login"),
    )
    await act(async () => {
      await result.current.signInDevice({
        email: "kiosk@grc.test",
        password: "secret",
      })
    })
    await act(async () => {
      await result.current.signInStudent({
        email: "student@grc.test",
        password: "secret",
      })
    })
    expect(result.current.state).toMatchObject({
      status: "student-active",
      studentToken: "student-token",
      studentUser,
    })
    expect(store.write).toHaveBeenCalledTimes(1)

    act(() => result.current.finishStudent())
    await act(async () =>
      result.current.signInStudent({
        email: "staff@grc.test",
        password: "secret",
      }),
    )
    expect(result.current.state).toMatchObject({
      status: "student-login",
      error: "This account is not authorized to view a Student queue.",
    })
    expect(fetchMock.mock.calls[4]?.[1]?.headers).toMatchObject({
      Authorization: "Bearer staff-token",
    })
  })

  it("clears the Student session synchronously and device sign-out clears every local token even when revocation fails", async () => {
    const store = createTokenStore()
    fetchMock
      .mockResolvedValueOnce(authResponse("device-token"))
      .mockResolvedValueOnce(authResponse("student-token", studentUser))
      .mockRejectedValueOnce(new Error("offline"))
      .mockRejectedValueOnce(new Error("offline"))
    const { result } = renderHook(() =>
      useQueueKioskSession({ tokenStore: store }),
    )
    await waitFor(() =>
      expect(result.current.state.status).toBe("device-login"),
    )
    await act(async () => {
      await result.current.signInDevice({
        email: "kiosk@grc.test",
        password: "secret",
      })
    })
    await act(async () => {
      await result.current.signInStudent({
        email: "student@grc.test",
        password: "secret",
      })
    })

    act(() => result.current.finishStudent())
    expect(result.current.state.status).toBe("student-login")
    await Promise.resolve()
    expect(fetchMock.mock.calls[2]?.[1]?.headers).toMatchObject({
      Authorization: "Bearer student-token",
    })

    act(() => result.current.signOutDevice())
    expect(result.current.state.status).toBe("device-login")
    expect(store.clear).toHaveBeenCalledOnce()
  })

  it("revokes a device token that arrives after device sign-out without persisting it", async () => {
    let resolveLogin: ((response: Response) => void) | undefined
    const store = createTokenStore()
    fetchMock
      .mockImplementationOnce(
        () =>
          new Promise<Response>((resolve) => {
            resolveLogin = resolve
          }),
      )
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
    const { result } = renderHook(() =>
      useQueueKioskSession({ tokenStore: store }),
    )
    await waitFor(() =>
      expect(result.current.state.status).toBe("device-login"),
    )

    void result.current.signInDevice({
      email: "kiosk@grc.test",
      password: "secret",
    })
    act(() => result.current.signOutDevice())
    resolveLogin?.(authResponse("late-device-token"))

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2))
    expect(store.write).not.toHaveBeenCalled()
    expect(fetchMock.mock.calls[1]?.[1]?.headers).toMatchObject({
      Authorization: "Bearer late-device-token",
    })
  })

  it("revokes a Student token that arrives after device sign-out", async () => {
    let resolveStudent: ((response: Response) => void) | undefined
    const store = createTokenStore()
    fetchMock
      .mockResolvedValueOnce(authResponse("device-token"))
      .mockImplementationOnce(
        () =>
          new Promise<Response>((resolve) => {
            resolveStudent = resolve
          }),
      )
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
    const { result } = renderHook(() =>
      useQueueKioskSession({ tokenStore: store }),
    )
    await waitFor(() =>
      expect(result.current.state.status).toBe("device-login"),
    )
    await act(async () => {
      await result.current.signInDevice({
        email: "kiosk@grc.test",
        password: "secret",
      })
    })
    void result.current.signInStudent({
      email: "student@grc.test",
      password: "secret",
    })
    act(() => result.current.signOutDevice())
    resolveStudent?.(authResponse("late-student-token", studentUser))
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(4))
    expect(result.current.state.status).toBe("device-login")
    expect(fetchMock.mock.calls[3]?.[1]?.headers).toMatchObject({
      Authorization: "Bearer late-student-token",
    })
  })
})
