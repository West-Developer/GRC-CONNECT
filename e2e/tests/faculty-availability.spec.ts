import { expect, test } from "@playwright/test"

import { authenticateViaApi } from "../fixtures/auth"
import { selectOption } from "../fixtures/select"

// Journey 3: Faculty availability submission.

test("journey 3 — Faculty submits a new availability window", async ({
  page,
  request,
}) => {
  await authenticateViaApi(page, request, "faculty")
  await page.goto("/portal/availability-preferences")

  await expect(
    page.getByRole("heading", { name: "Availability and preferences" }),
  ).toBeVisible()

  // "Academic term" appears twice on this page (availability form and
  // preference form); selectOption falls back to the first match when a
  // label is ambiguous, and the availability form's term select is first in
  // DOM order. Academic term has no default (placeholder shown until
  // chosen); day_of_week defaults to Monday, so only the term needs an
  // explicit pick. The seeded active term is "2022-2023 · 2nd".
  await selectOption(page, "Academic term", "2022-2023 · 2nd")
  await page.getByLabel("Start time").fill("08:00:00")
  await page.getByLabel("End time").fill("10:00:00")
  await page.getByRole("button", { name: "Save availability" }).click()

  // A newly saved window shows up in the existing-availability list rendered
  // alongside the form — the exact assertion the vitest suite's own
  // faculty-input-workspace.test.tsx already exercises for the edit path.
  await expect(page.getByText("Monday", { exact: false }).first()).toBeVisible()
})
