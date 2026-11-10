import { expect, test } from "@playwright/test"

import { authenticateViaApi } from "../fixtures/auth"

// Journey 10: unauthorized cross-role access denial.
// Journey 15 (partial): compliance report authorization only — the report
// content itself does not exist yet (Phase 7c, blocked on institutional
// content decision). This spec covers exactly the half that exists: a
// non-Registrar-Head role is denied the route.
//
// The frontend's own client-side guard (PortalModulePage) renders a "Portal
// module not found" state for any moduleId not present in the signed-in
// role's own module list — it never reaches a Faculty/Registrar-Head
// component tree at all. The server-side Policy is the actual authorization
// boundary (this client guard is navigation convenience only, per
// auth-route-guards.tsx's own docblock); a real cross-role API 403 is
// exercised by the backend's own Policy test suite, not duplicated here.

test.describe("cross-role authorization", () => {
  test("access matrix — IT Control can open only its three assigned workspaces", async ({
    page,
    request,
  }) => {
    await authenticateViaApi(page, request, "it_admin")
    await page.goto("/portal")

    const navigation = page.getByRole("navigation", {
      name: "Role portal navigation",
    })
    await expect(navigation.getByRole("link")).toHaveCount(3)
    await expect(
      navigation.getByRole("link", { name: "Student Accounts" }),
    ).toBeVisible()
    await expect(
      navigation.getByRole("link", { name: "Faculty Accounts" }),
    ).toBeVisible()
    await expect(
      navigation.getByRole("link", { name: "Enrollment Overrides" }),
    ).toHaveCount(0)

    // The Enrollment Overrides module was removed: its old address is simply not a module.
    await page.goto("/portal/it-control-enrollment-override")
    await expect(
      page.getByRole("heading", { name: "Portal module not found" }),
    ).toBeVisible()

    await page.goto("/portal/audit-logs")
    await expect(
      page.getByRole("heading", { name: "Portal module not found" }),
    ).toBeVisible()
  })

  test("journey 10 — a Student cannot reach a Faculty-only workspace", async ({
    page,
    request,
  }) => {
    await authenticateViaApi(page, request, "student")
    await page.goto("/portal/availability-preferences")

    await expect(
      page.getByRole("heading", { name: "Portal module not found" }),
    ).toBeVisible()
  })

  test("journey 15 (partial) — a Student is denied the Registrar Head's CHED reports route", async ({
    page,
    request,
  }) => {
    await authenticateViaApi(page, request, "student")
    await page.goto("/portal/ched-reports")

    await expect(
      page.getByRole("heading", { name: "Portal module not found" }),
    ).toBeVisible()
  })
})
