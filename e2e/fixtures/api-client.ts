import type { APIRequestContext } from "@playwright/test"

import { SEED_PASSWORD, type SeedRole, SEED_IDENTITIES } from "./seed-identities"

const API_BASE_URL = process.env.E2E_API_BASE_URL ?? "http://127.0.0.1:8000"

export interface AuthSession {
  token: string
  userId: number
  role: string
}

interface LoginResponse {
  data: {
    token: string
    user: { id: number; role: string }
  }
}

/**
 * Signs a seeded identity in over the real API — the same
 * POST /api/v1/auth/login the frontend calls — and returns a bearer token
 * for arranging preconditions or driving further API calls directly.
 * Playwright's `request` fixture is a separate HTTP client from the browser
 * context, so this never touches the page or localStorage.
 */
export async function loginAs(
  request: APIRequestContext,
  role: SeedRole,
): Promise<AuthSession> {
  return loginWithEmail(request, SEED_IDENTITIES[role].email)
}

/**
 * For the three student lifecycle scenario accounts (SEED_STUDENT_SCENARIOS)
 * — not one of the 9 role identities loginAs covers — or any other seeded
 * email a journey needs to authenticate as directly.
 */
export async function loginWithEmail(
  request: APIRequestContext,
  email: string,
): Promise<AuthSession> {
  const response = await request.post(`${API_BASE_URL}/api/v1/auth/login`, {
    data: { email, password: SEED_PASSWORD },
  })

  if (!response.ok()) {
    throw new Error(
      `loginWithEmail("${email}") failed: ${response.status()} ${await response.text()}`,
    )
  }

  const body = (await response.json()) as LoginResponse

  await agreeToPrivacyNotice(request, body.data.token)

  return {
    token: body.data.token,
    userId: body.data.user.id,
    role: body.data.user.role,
  }
}

/**
 * A person meets the Data Privacy Notice before the portal (ADR 0055). Journeys that are not about
 * the notice agree to it over the API right after signing in, the way a person would on their first
 * visit, so they start in the portal. The notice's own journey is in auth.spec.ts.
 */
export async function agreeToPrivacyNotice(
  request: APIRequestContext,
  token: string,
): Promise<void> {
  const info = await request.get(`${API_BASE_URL}/api/v1/privacy-notice`)

  if (!info.ok()) {
    throw new Error(`privacy-notice failed: ${info.status()} ${await info.text()}`)
  }

  const { data } = (await info.json()) as { data: { version: string } }
  const response = await request.post(
    `${API_BASE_URL}/api/v1/auth/privacy-notice/accept`,
    {
      data: { notice_version: data.version },
      headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
    },
  )

  // The queue kiosk is a device, not a person: it is never asked, and 403 is the right answer.
  if (!response.ok() && response.status() !== 403) {
    throw new Error(
      `privacy-notice accept failed: ${response.status()} ${await response.text()}`,
    )
  }
}

/** Bearer-authenticated JSON request helper for API-arranged preconditions. */
export class ApiArranger {
  constructor(
    private readonly request: APIRequestContext,
    private readonly session: AuthSession,
  ) {}

  private authHeaders(): Record<string, string> {
    return {
      Authorization: `Bearer ${this.session.token}`,
      Accept: "application/json",
    }
  }

  async get(path: string): Promise<unknown> {
    const response = await this.request.get(`${API_BASE_URL}${path}`, {
      headers: this.authHeaders(),
    })
    return assertOkJson(response, "GET", path)
  }

  async post(path: string, body?: unknown): Promise<unknown> {
    const response = await this.request.post(`${API_BASE_URL}${path}`, {
      headers: this.authHeaders(),
      data: body ?? {},
    })
    return assertOkJson(response, "POST", path)
  }

  async patch(path: string, body: unknown): Promise<unknown> {
    const response = await this.request.patch(`${API_BASE_URL}${path}`, {
      headers: this.authHeaders(),
      data: body,
    })
    return assertOkJson(response, "PATCH", path)
  }
}

async function assertOkJson(
  response: Awaited<ReturnType<APIRequestContext["get"]>>,
  method: string,
  path: string,
): Promise<unknown> {
  if (!response.ok()) {
    throw new Error(
      `${method} ${path} failed: ${response.status()} ${await response.text()}`,
    )
  }

  return response.json() as Promise<unknown>
}
