import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { createApiAuthGateway } from "@/features/auth/api-auth-gateway"
import {
  createAuthTokenStore,
  type TokenStorageLike,
} from "@/features/auth/auth-token"
import {
  setAuthTokenProvider,
  setUnauthorizedHandler,
} from "@/features/services/api-client"
import { agreedPrivacyNotice } from "@/tests/privacy-notice-fixture"

function createMemoryStorage(): TokenStorageLike {
  const values = new Map<string, string>()

  return {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => {
      values.set(key, value)
    },
    removeItem: (key) => {
      values.delete(key)
    },
  }
}

const validUser = {
  type: "user" as const,
  id: 1,
  name: "Seed Student",
  email: "student.seed@grc.test",
  role: "student",
  role_label: "Student",
  privacy_notice: agreedPrivacyNotice,
  status: "active",
}

const queueKioskUser = {
  ...validUser,
  id: 99,
  name: "Queue Kiosk",
  email: "queue-kiosk.seed@grc.test",
  role: "queue_kiosk" as const,
  role_label: "Queue Kiosk",
}

describe("createApiAuthGateway", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it("implements the whole gateway contract", () => {
    const gateway = createApiAuthGateway(
      createAuthTokenStore(createMemoryStorage()),
    )

    expect(typeof gateway.signIn).toBe("function")
    expect(typeof gateway.restore).toBe("function")
    expect(typeof gateway.signOut).toBe("function")
    expect(typeof gateway.clearSession).toBe("function")
    expect(typeof gateway.persistenceAvailable).toBe("function")
  })

  it("signIn stores the token and returns a session built from the response user", async () => {
    const tokenStore = createAuthTokenStore(createMemoryStorage())
    setAuthTokenProvider(() => tokenStore.read())
    const gateway = createApiAuthGateway(tokenStore)

    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: {
            type: "auth-session",
            token: "1|plaintext-token",
            token_type: "Bearer",
            expires_at: null,
            user: validUser,
          },
        }),
        { status: 200 },
      ),
    )

    const session = await gateway.signIn({
      email: "student.seed@grc.test",
      password: "secret",
    })

    expect(tokenStore.read()).toBe("1|plaintext-token")
    expect(session).toMatchObject({
      userId: "1",
      displayName: "Seed Student",
      role: "student",
    })
  })

  it("signIn maps a 401 to the shared invalid-credentials error without leaking detail", async () => {
    const gateway = createApiAuthGateway(
      createAuthTokenStore(createMemoryStorage()),
    )
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          error: {
            code: "UNAUTHENTICATED",
            message: "The provided credentials are incorrect.",
            errors: {},
            request_id: "req-1",
          },
        }),
        { status: 401 },
      ),
    )

    await expect(
      gateway.signIn({ email: "student.seed@grc.test", password: "wrong" }),
    ).rejects.toMatchObject({ code: "INVALID_CREDENTIALS" })
  })

  it("revokes a successful queue kiosk login without persisting its token to the portal", async () => {
    const tokenStore = createAuthTokenStore(createMemoryStorage())
    const gateway = createApiAuthGateway(tokenStore)
    fetchMock
      .mockResolvedValueOnce(
        new Response(
          JSON.stringify({
            data: {
              type: "auth-session",
              token: "2|kiosk-token",
              token_type: "Bearer",
              expires_at: null,
              user: queueKioskUser,
            },
          }),
          { status: 200 },
        ),
      )
      .mockResolvedValueOnce(new Response(null, { status: 204 }))

    try {
      await expect(
        gateway.signIn({ email: queueKioskUser.email, password: "secret" }),
      ).rejects.toMatchObject({
        code: "QUEUE_KIOSK_REQUIRES_DEVICE_PORTAL",
      })

      expect(tokenStore.read()).toBeNull()
      expect(fetchMock).toHaveBeenNthCalledWith(
        2,
        "http://127.0.0.1:8000/api/v1/auth/logout",
        {
          method: "POST",
          headers: {
            Accept: "application/json",
            Authorization: "Bearer 2|kiosk-token",
            "Content-Type": "application/json",
          },
          body: "{}",
          credentials: "omit",
          cache: "no-store",
          signal: undefined,
        },
      )
    } finally {
      fetchMock.mockReset()
    }
  })

  it("rejects a kiosk sign-in without invoking the portal handler when its revoke returns 401", async () => {
    const onUnauthorized = vi.fn()
    const gateway = createApiAuthGateway(
      createAuthTokenStore(createMemoryStorage()),
    )
    setUnauthorizedHandler(onUnauthorized)
    fetchMock
      .mockResolvedValueOnce(
        new Response(
          JSON.stringify({
            data: {
              type: "auth-session",
              token: "2|kiosk-token",
              token_type: "Bearer",
              expires_at: null,
              user: queueKioskUser,
            },
          }),
          { status: 200 },
        ),
      )
      .mockResolvedValueOnce(
        new Response(
          JSON.stringify({
            error: {
              code: "UNAUTHENTICATED",
              message: "Authentication is required.",
              errors: {},
              request_id: "req-kiosk-revoke",
            },
          }),
          { status: 401 },
        ),
      )

    try {
      await expect(
        gateway.signIn({ email: queueKioskUser.email, password: "secret" }),
      ).rejects.toMatchObject({
        code: "QUEUE_KIOSK_REQUIRES_DEVICE_PORTAL",
      })

      expect(onUnauthorized).not.toHaveBeenCalled()
    } finally {
      fetchMock.mockReset()
      setUnauthorizedHandler(() => undefined)
    }
  })

  it("restore returns null immediately when no token is stored", async () => {
    const tokenStore = createAuthTokenStore(createMemoryStorage())
    const gateway = createApiAuthGateway(tokenStore)

    await expect(gateway.restore?.()).resolves.toBeNull()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("restore rebuilds the session from /me when a token is stored", async () => {
    const storage = createMemoryStorage()
    const tokenStore = createAuthTokenStore(storage)
    tokenStore.write("1|plaintext-token")
    setAuthTokenProvider(() => tokenStore.read())
    const gateway = createApiAuthGateway(tokenStore)

    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: validUser }), { status: 200 }),
    )

    const session = await gateway.restore?.()

    expect(session).toMatchObject({ userId: "1", role: "student" })
  })

  it("restore carries where the person stands on the Data Privacy Notice, so the portal can ask", async () => {
    const tokenStore = createAuthTokenStore(createMemoryStorage())
    tokenStore.write("1|plaintext-token")
    setAuthTokenProvider(() => tokenStore.read())
    const gateway = createApiAuthGateway(tokenStore)

    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: {
            ...validUser,
            privacy_notice: {
              version: "2026-10-18",
              accepted_version: "2025-01-01",
              accepted_at: "2025-01-01T00:00:00Z",
              requires_acceptance: true,
            },
          },
        }),
        { status: 200 },
      ),
    )

    const session = await gateway.restore?.()

    expect(session?.privacyNotice).toEqual({
      version: "2026-10-18",
      acceptedVersion: "2025-01-01",
      requiresAcceptance: true,
    })
  })

  it("restore clears an unverifiable token and returns null", async () => {
    const storage = createMemoryStorage()
    const tokenStore = createAuthTokenStore(storage)
    tokenStore.write("1|stale-token")
    setAuthTokenProvider(() => tokenStore.read())
    const gateway = createApiAuthGateway(tokenStore)

    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          error: {
            code: "UNAUTHENTICATED",
            message: "Authentication is required.",
            errors: {},
            request_id: "req-2",
          },
        }),
        { status: 401 },
      ),
    )

    const session = await gateway.restore?.()

    expect(session).toBeNull()
    expect(tokenStore.read()).toBeNull()
  })

  it("persistenceAvailable is true after a successful sign-in", async () => {
    const gateway = createApiAuthGateway(
      createAuthTokenStore(createMemoryStorage()),
    )
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: {
            type: "auth-session",
            token: "1|plaintext-token",
            token_type: "Bearer",
            expires_at: null,
            user: validUser,
          },
        }),
        { status: 200 },
      ),
    )

    await gateway.signIn({ email: "student.seed@grc.test", password: "secret" })

    expect(gateway.persistenceAvailable?.()).toBe(true)
  })

  it("persistenceAvailable is false when the token store cannot write", async () => {
    const throwingStorage: TokenStorageLike = {
      getItem: () => null,
      setItem: () => {
        throw new Error("storage unavailable")
      },
      removeItem: () => undefined,
    }
    const gateway = createApiAuthGateway(createAuthTokenStore(throwingStorage))
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: {
            type: "auth-session",
            token: "1|plaintext-token",
            token_type: "Bearer",
            expires_at: null,
            user: validUser,
          },
        }),
        { status: 200 },
      ),
    )

    await gateway.signIn({ email: "student.seed@grc.test", password: "secret" })

    expect(gateway.persistenceAvailable?.()).toBe(false)
  })

  it("signOut clears the local token even when the server revoke fails", async () => {
    const storage = createMemoryStorage()
    const tokenStore = createAuthTokenStore(storage)
    tokenStore.write("1|plaintext-token")
    setAuthTokenProvider(() => tokenStore.read())
    const gateway = createApiAuthGateway(tokenStore)

    fetchMock.mockRejectedValue(new TypeError("network down"))

    await gateway.signOut?.()

    expect(tokenStore.read()).toBeNull()
  })

  it("clearSession clears the local token without a server round-trip", () => {
    const storage = createMemoryStorage()
    const tokenStore = createAuthTokenStore(storage)
    tokenStore.write("1|plaintext-token")
    const gateway = createApiAuthGateway(tokenStore)

    gateway.clearSession()

    expect(tokenStore.read()).toBeNull()
    expect(fetchMock).not.toHaveBeenCalled()
  })
})
