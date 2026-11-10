import { expect, test, type Page } from "@playwright/test";

import { authenticateViaApi } from "../fixtures/auth";

/**
 * Stakeholder Doc 13 (mobile): on a phone the bell takes the top bar's
 * right-hand spot, Sign out lives at the bottom of the hamburger drawer, the
 * login form scrolls into view, and no portal page scrolls sideways.
 *
 * A 390x844 viewport with touch and `isMobile` reproduces the phone layout
 * (Playwright forbids changing `defaultBrowserType` inside a describe, so
 * `devices["Pixel 5"]` is not spread here).
 */
test.use({
  viewport: { width: 390, height: 844 },
  hasTouch: true,
  isMobile: true,
});

// String scripts: this package's tsconfig has no DOM lib (see mobile-navigation.spec.ts).
async function hasHorizontalScroll(page: Page): Promise<boolean> {
  return (await page.evaluate(
    "document.documentElement.scrollWidth > document.documentElement.clientWidth + 1",
  )) as boolean;
}

test("the phone top bar shows the bell and no Sign out, which sits at the bottom of the drawer", async ({
  page,
  request,
}) => {
  await authenticateViaApi(page, request, "student");
  await page.goto("/portal");

  const banner = page.locator("header.portal-topbar");
  await expect(
    banner.getByRole("button", { name: /^Notifications/ }),
  ).toBeVisible();
  await expect(banner.getByRole("button", { name: "Sign out" })).toBeHidden();

  // One row, with the bell to the right of the breadcrumb.
  const breadcrumb = await banner
    .getByRole("navigation", { name: "Breadcrumb" })
    .boundingBox();
  const bell = await banner
    .getByRole("button", { name: /^Notifications/ })
    .boundingBox();
  expect(breadcrumb).not.toBeNull();
  expect(bell).not.toBeNull();
  expect(bell!.x).toBeGreaterThan(breadcrumb!.x + breadcrumb!.width - 1);
  expect(Math.abs(bell!.y - breadcrumb!.y)).toBeLessThan(40);

  await page.getByRole("button", { name: "Open portal navigation" }).click();
  const drawer = page.getByRole("dialog", { name: "GRC Connect navigation" });
  const signOut = drawer.getByRole("button", { name: "Sign out" });
  await expect(signOut).toBeVisible();

  const lastLink = await drawer
    .getByRole("navigation")
    .getByRole("link")
    .last()
    .boundingBox();
  const signOutBox = await signOut.boundingBox();
  expect(signOutBox!.y).toBeGreaterThan(lastLink!.y);

  await signOut.click();
  await expect(page).toHaveURL(/\/$/);
});

test("opening the login page on a phone scrolls to the email and password form", async ({
  page,
}) => {
  await page.goto("/login");

  const email = page.getByLabel("Email address");
  await expect(email).toBeVisible();
  await expect(email).toBeInViewport();
  await expect(page.getByLabel("Password")).toBeInViewport();
  expect(await page.evaluate("window.scrollY")).toBeGreaterThan(0);
});

for (const path of [
  "/portal",
  "/portal/enrollment",
  "/portal/schedule",
  "/portal/grades",
  "/portal/student-information",
]) {
  test(`the student page ${path} does not scroll sideways on a phone`, async ({
    page,
    request,
  }) => {
    await authenticateViaApi(page, request, "student");
    await page.goto(path);
    await expect(page.getByRole("heading", { level: 1 }).first()).toBeVisible();

    expect(await hasHorizontalScroll(page)).toBe(false);
  });
}

test("the Program Chair drawer lists Irregular Advising and the Enrollment Dashboard right after Enrollment", async ({
  page,
  request,
}) => {
  await authenticateViaApi(page, request, "program_chair");
  await page.goto("/portal");
  await page.getByRole("button", { name: "Open portal navigation" }).click();

  const names = await page
    .getByRole("dialog", { name: "GRC Connect navigation" })
    .getByRole("navigation")
    .getByRole("link")
    .allInnerTexts();

  expect(names.slice(0, 4).map((name) => name.trim())).toEqual([
    "GRC Connect",
    "Enrollment",
    "Irregular Advising",
    "Enrollment Dashboard",
  ]);
  // Schedule is a normal, enabled link.
  await expect(
    page
      .getByRole("dialog", { name: "GRC Connect navigation" })
      .getByRole("link", { name: "Schedule" }),
  ).not.toHaveAttribute("aria-disabled", "true");
});
