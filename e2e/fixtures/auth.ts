import type { APIRequestContext, Page } from "@playwright/test"
import { expect } from "@playwright/test"

import { loginAs } from "./api-client"
import { SEED_IDENTITIES, SEED_PASSWORD, type SeedRole } from "./seed-identities"

/** The one storage key auth-token.ts owns (frontend/src/features/auth/auth-token.ts). */
const AUTH_TOKEN_STORAGE_KEY = "grc.auth-token.v1"

/**
 * Drives the real login form — for journeys that specifically exercise sign-in
 * itself (journey 1) or its failure paths (journey 11, validation errors).
 */
export async function signInViaUi(page: Page, role: SeedRole): Promise<void> {
  const identity = SEED_IDENTITIES[role]

  await page.goto("/login")
  await page.getByLabel("Email address").fill(identity.email)
  // Exact match: "Password" (substring, case-insensitive) also matches the
  // adjacent "Show password" visibility-toggle button's aria-label.
  await page.getByLabel("Password", { exact: true }).fill(SEED_PASSWORD)
  await page.getByRole("button", { name: "Sign in" }).click()
  await expect(page).toHaveURL(/\/portal/)
  await agreeToPrivacyNoticeIfAsked(page)
}

/**
 * The first sign-in of an account that has not agreed to the Data Privacy Notice shows the notice
 * before the portal (ADR 0055). Reads it and agrees, the way a person would; does nothing when the
 * account has already agreed.
 */
export async function agreeToPrivacyNoticeIfAsked(page: Page): Promise<void> {
  const agree = page.getByRole("button", { name: "I agree and continue" })

  if (await agree.isVisible({ timeout: 3000 }).catch(() => false)) {
    await page
      .getByRole("checkbox", {
        name: /I have read and understood the Data Privacy Notice/,
      })
      .check()
    await agree.click()
    await expect(agree).toBeHidden()
  }
}

/**
 * Signs in over the real API (loginAs) and arranges for the resulting token
 * to be present in localStorage *before* the app's own JS ever runs, via
 * Playwright's addInitScript — so a journey that isn't *about* login can
 * start already authenticated. Still exercises real token validation:
 * AuthProvider's restore() calls GET /auth/me with this token on first load,
 * so an invalid or expired token surfaces exactly as it would for a real
 * user, not silently succeeds.
 *
 * Deliberately NOT implemented as goto("/login") + page.evaluate(setItem) +
 * goto(target): that sequence races a real bug reproduced while writing this
 * fixture. restore()'s GET /auth/me is still in flight on the first
 * navigation when the second goto() fires; the browser aborts it, the old
 * page's `catch { tokenStore.clear() }` still runs synchronously against the
 * shared origin's localStorage before the new document loads, and the
 * freshly-set token is gone by the time the target page mounts.
 * addInitScript sidesteps the whole race — the token exists before any
 * restore() cycle ever starts, so there is only ever one clean fetch.
 */
export async function authenticateViaApi(
  page: Page,
  request: APIRequestContext,
  role: SeedRole,
): Promise<string> {
  const session = await loginAs(request, role)

  await page.context().addInitScript(
    ({ key, token }: { key: string; token: string }) => {
      window.localStorage.setItem(key, token)
    },
    { key: AUTH_TOKEN_STORAGE_KEY, token: session.token },
  )

  return session.token
}
