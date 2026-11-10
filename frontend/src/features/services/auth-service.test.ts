import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import {
  acceptPrivacyNotice,
  AUTH_LOGIN_PATH,
  AUTH_PRIVACY_NOTICE_ACCEPT_PATH,
  fetchCurrentUser,
  fetchPrivacyNoticeInfo,
  login,
  logout,
  PRIVACY_NOTICE_PATH,
} from "@/features/services/auth-service"
import { setAuthTokenProvider } from "@/features/services/api-client"
import { agreedPrivacyNotice } from "@/tests/privacy-notice-fixture"

const validUser = {
  type: "user" as const,
  id: 7,
  name: "Seed Registrar Head",
  email: "registrar-head.seed@grc.test",
  role: "registrar_head",
  role_label: "Registrar Head",
  privacy_notice: agreedPrivacyNotice,
  status: "active",
}

const validAuthEnvelope = {
  data: {
    type: "auth-session" as const,
    token: "1|plaintext-token-value",
    token_type: "Bearer" as const,
    expires_at: "2026-07-27T18:46:36Z",
    user: validUser,
  },
}

describe("auth-service", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    setAuthTokenProvider(() => "1|plaintext-token-value")
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it("login parses the exact auth envelope and requests the documented path", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify(validAuthEnvelope), { status: 200 }),
    )

    await expect(
      login({ email: "registrar-head.seed@grc.test", password: "secret" }),
    ).resolves.toEqual({
      kind: "authenticated",
      session: {
        token: "1|plaintext-token-value",
        expiresAt: "2026-07-27T18:46:36Z",
        user: validUser,
      },
    })

    expect(fetchMock).toHaveBeenCalledWith(
      `http://127.0.0.1:8000${AUTH_LOGIN_PATH}`,
      expect.objectContaining({ method: "POST" }),
    )
  })

  it("login rejects an envelope with an undeclared field", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: { ...validAuthEnvelope.data, unexpected: "leak" },
        }),
        { status: 200 },
      ),
    )

    await expect(
      login({ email: "registrar-head.seed@grc.test", password: "secret" }),
    ).rejects.toMatchObject({ kind: "contract" })
  })

  it("fetchCurrentUser parses the exact user envelope", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: validUser }), { status: 200 }),
    )

    await expect(fetchCurrentUser()).resolves.toEqual(validUser)
  })

  it("fetchCurrentUser rejects a response missing a required field", async () => {
    const { role_label: _roleLabel, ...incomplete } = validUser
    void _roleLabel

    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: incomplete }), { status: 200 }),
    )

    await expect(fetchCurrentUser()).rejects.toMatchObject({
      kind: "contract",
    })
  })

  it("fetchCurrentUser rejects a user that carries no privacy notice status", async () => {
    const { privacy_notice: _status, ...incomplete } = validUser
    void _status

    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: incomplete }), { status: 200 }),
    )

    await expect(fetchCurrentUser()).rejects.toMatchObject({
      kind: "contract",
    })
  })

  it("reads the public Data Privacy Notice version and contact", async () => {
    const info = {
      type: "privacy-notice" as const,
      version: "2026-10-18",
      effective_date: "2026-10-18",
      controller: "Global Reciprocal Colleges",
      contact: { name: null, email: "info@grc.edu.ph", phone: null },
    }
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: info }), { status: 200 }),
    )

    await expect(fetchPrivacyNoticeInfo()).resolves.toEqual(info)
    expect(fetchMock).toHaveBeenCalledWith(
      `http://127.0.0.1:8000${PRIVACY_NOTICE_PATH}`,
      expect.objectContaining({ method: "GET" }),
    )
  })

  it("agrees to the version that was read and returns the updated user", async () => {
    const updated = {
      ...validUser,
      privacy_notice: {
        version: "2026-10-18",
        accepted_version: "2026-10-18",
        accepted_at: "2026-10-18T01:02:03Z",
        requires_acceptance: false,
      },
    }
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: updated }), { status: 200 }),
    )

    await expect(acceptPrivacyNotice("2026-10-18")).resolves.toEqual(updated)

    const [url, request] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe(`http://127.0.0.1:8000${AUTH_PRIVACY_NOTICE_ACCEPT_PATH}`)
    expect(request.method).toBe("POST")
    expect(typeof request.body).toBe("string")
    expect(JSON.parse(request.body as string)).toEqual({
      notice_version: "2026-10-18",
    })
  })

  it("logout completes without throwing on a 204 response", async () => {
    fetchMock.mockResolvedValue(new Response(null, { status: 204 }))

    await expect(logout()).resolves.toBeUndefined()
  })
})
