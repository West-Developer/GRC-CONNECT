import { expect, test } from "@playwright/test"

import { NONEXISTENT_EMAIL } from "../fixtures/seed-identities"

// Journey 11: validation errors rendered in the correct fields.
// Journey 12: throttle behavior.
//
// Both run in the "throttle-isolated" Playwright project (see
// playwright.config.ts): routes/api.php keys the login limiter
// (throttle:30,1) per IP, not per credential, and every worker shares one
// source IP. A tripped limiter here would otherwise block every other
// spec's sign-in for the rest of that window, so this file is isolated to
// its own single-worker, non-parallel project.

test("journey 11 — an invalid email renders its error against the email field, not a generic banner", async ({
  page,
}) => {
  await page.goto("/login")
  await page.getByLabel("Email address").fill("not-an-email")
  await page.getByLabel("Password", { exact: true }).fill("irrelevant")
  await page.getByRole("button", { name: "Sign in" }).click()

  const emailField = page.getByLabel("Email address")
  await expect(emailField).toHaveAttribute("aria-invalid", "true")
  await expect(page.locator("#login-email-error")).toHaveText(
    "Enter a valid email address.",
  )
  // The password field, uninvolved in this failure, must not also be flagged.
  await expect(
    page.getByLabel("Password", { exact: true }),
  ).toHaveAttribute("aria-invalid", "false")
})

test("journey 12 — repeated failed logins trip the rate limiter", async ({
  page,
}) => {
  await page.goto("/login")

  // routes/api.php:39 sets throttle:30,1 on POST /auth/login. 31 real
  // attempts against a nonexistent email guarantees the 31st is throttled,
  // without touching any seeded identity's own state.
  //
  // The frontend does not currently render throttle-specific copy — every
  // sign-in failure (bad credentials or 429) sets the same generic
  // "not recognized" message (login-page.tsx's submitLogin catch block is
  // unconditional). So this asserts the real thing §14.3 asks for — the
  // limiter actually engages — at the HTTP layer via the response status,
  // rather than inventing UI text that doesn't exist.
  let lastStatus = 0

  for (let attempt = 0; attempt < 31; attempt += 1) {
    const responsePromise = page.waitForResponse((response) =>
      response.url().includes("/api/v1/auth/login"),
    )
    await page.getByLabel("Email address").fill(NONEXISTENT_EMAIL)
    await page.getByLabel("Password", { exact: true }).fill("wrong-password")
    await page.getByRole("button", { name: "Sign in" }).click()
    const response = await responsePromise
    lastStatus = response.status()
    await page.getByRole("alert", { name: "Sign-in errors" }).waitFor()
  }

  expect(lastStatus).toBe(429)
})
