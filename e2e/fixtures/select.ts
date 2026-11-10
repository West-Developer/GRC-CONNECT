import type { Locator, Page } from "@playwright/test"

/**
 * Radix Select renders no options in the DOM until opened (unlike a native
 * <select>, which always has every <option> present) — the same fact that
 * drove the vitest migration's selectOption test helper in Phase 8b. Opens
 * the trigger, clicks the matching option by its accessible name. Playwright's
 * click() already auto-waits for the trigger to be enabled (reference-data
 * -backed triggers start disabled until their query resolves), so no extra
 * wait is needed for that — only for resolving which trigger, when a label
 * repeats on the page.
 *
 * `scope` narrows the trigger lookup for forms that repeat a label (e.g. two
 * "Academic term" selects on the same page) — pass a card/section Locator
 * instead of the default `page`.
 */
export async function selectOption(
  page: Page,
  triggerLabel: string,
  optionName: string,
  scope: Page | Locator = page,
): Promise<void> {
  const trigger = scope.getByLabel(triggerLabel, { exact: true })
  const candidateCount = await trigger.count()
  await (candidateCount > 1 ? trigger.first() : trigger).click()
  await page.getByRole("option", { name: optionName, exact: true }).click()
}
