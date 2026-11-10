import { expect, test } from "@playwright/test"

import { authenticateViaApi } from "../fixtures/auth"

// Journey: the Registrar Head edits the per-audience enrollment schedule —
// the ordinal labels ("1st Year" .. "Irregular Students", never "Year 1")
// and the fixed 8:00 AM / 11:59 PM window convention are both part of the
// original bug report this slice fixed, so this journey asserts both
// directly against the rendered form rather than only against the API.

test("the Registrar edits the five-audience enrollment schedule and sees it take effect", async ({
  page,
  request,
}) => {
  await authenticateViaApi(page, request, "registrar_head")

  await page.goto("/portal/academic-terms")
  await expect(page.getByRole("heading", { name: "Enrollment schedule" })).toBeVisible({
    timeout: 15_000,
  })
  // The heading depends only on the term list resolving; the form's own
  // date inputs populate separately, from a second query, via a `useEffect`
  // that resets the form once it resolves. Submitting before that lands
  // sends the still-empty term-wide date defaults and trips client-side
  // "Enter a date." validation — no request is even made.
  await expect(page.locator("#schedule-term-opens")).not.toHaveValue("", {
    timeout: 15_000,
  })

  // Ordinal labels, never "Year 1"/"Year 2"/etc.
  for (const label of [
    "1st Year",
    "2nd Year",
    "3rd Year",
    "4th Year",
    "Irregular Students",
  ]) {
    await expect(page.getByText(label, { exact: true }).first()).toBeVisible()
  }
  await expect(page.getByText(/^Year \d$/)).toHaveCount(0)

  // The fixed start/end time convention is stated, not editable.
  await expect(
    page.getByText(/Enrollment always starts at 8:00 AM/),
  ).toBeVisible()

  // Open the irregular window right now by widening its dates, and save.
  const yesterday = new Date(Date.now() - 24 * 60 * 60 * 1000)
    .toISOString()
    .slice(0, 10)
  const inThirtyDays = new Date(Date.now() + 30 * 24 * 60 * 60 * 1000)
    .toISOString()
    .slice(0, 10)

  await page.locator("#schedule-irregular_opens_at").fill(yesterday)
  await page.locator("#schedule-irregular_closes_at").fill(inThirtyDays)
  await page.getByRole("button", { name: "Save enrollment schedule" }).click()

  await expect(page.getByText("Enrollment schedule saved.")).toBeVisible({
    timeout: 15_000,
  })

  // The live status grid (only shown once the term is ongoing) reflects the
  // change without a page reload. Scoped to the audience card's own parent
  // div, not just any ancestor containing both texts (every ancestor up to
  // <body> would otherwise match too).
  // "Irregular Students" also labels the per-audience form row below — take
  // the first occurrence, which is this live-status card.
  const irregularCard = page
    .getByText("Irregular Students", { exact: true })
    .first()
    .locator("xpath=..")
  await expect(irregularCard.getByText("Open now")).toBeVisible()
})
