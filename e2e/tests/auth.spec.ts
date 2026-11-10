import { expect, test } from "@playwright/test"

import { signInViaUi } from "../fixtures/auth"

// Journey 1: authorized account sign-in and token persistence.
// Journey 2: protected-route redirect without a token.

test.describe("authentication", () => {
  test("journey 1 — sign-in persists the session across a reload", async ({
    page,
  }) => {
    await signInViaUi(page, "student")
    await expect(page).toHaveURL(/\/portal/)

    await page.reload()

    // A restored session must land back on the portal, not bounce to /login —
    // proves the token survived the reload, not just the in-memory session.
    await expect(page).toHaveURL(/\/portal/)
    await expect(
      page.getByRole("heading", { name: "Modules prepared for your role" }),
    ).toBeVisible()
  })

  test("journey 2 — an unauthenticated visit to a protected route redirects to /login", async ({
    page,
  }) => {
    await page.goto("/portal")

    await expect(page).toHaveURL(/\/login\?returnTo=/)
  })
})
