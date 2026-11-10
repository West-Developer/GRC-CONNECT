import AxeBuilder from "@axe-core/playwright"
import { expect, test } from "@playwright/test"

import { authenticateViaApi } from "../fixtures/auth"

// Closes the manual WCAG 2.1 AA / live-visual-verification gap deferred
// twice now — Phases 8a and 8b — purely because no browser was ever
// connected during either session. This is deliberately NOT a duplicate of
// the vitest-axe coverage already on all 19 workspaces (jsdom): it targets
// what jsdom cannot see — real layout and focus order, 200% zoom, and
// whether prefers-reduced-motion actually suppresses Phase 8b's `motion`
// library's JS-driven inline transforms, which the existing CSS blanket
// rule (globals.css) cannot reach by construction (ADR 0015, decision 3).

const pages = [
  { name: "landing page", path: "/" },
  { name: "login page", path: "/login" },
]

for (const { name, path } of pages) {
  test(`accessibility — ${name} has no critical or serious axe violations`, async ({
    page,
  }) => {
    await page.goto(path)
    const results = await new AxeBuilder({ page })
      .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"])
      .analyze()

    const seriousOrWorse = results.violations.filter((v) =>
      ["critical", "serious"].includes(v.impact ?? ""),
    )
    expect(
      seriousOrWorse,
      seriousOrWorse.map((v) => `${v.id}: ${v.help} (${v.nodes.length} nodes)`).join("\n"),
    ).toEqual([])
  })
}

test("accessibility — portal overview and Enrollment (the originally reported page, later folded from a standalone Eligible Subjects page into Enrollment) have no critical or serious axe violations", async ({
  page,
  request,
}) => {
  await authenticateViaApi(page, request, "student")

  await page.goto("/portal")
  const overviewResults = await new AxeBuilder({ page })
    .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"])
    .analyze()
  const overviewSerious = overviewResults.violations.filter((v) =>
    ["critical", "serious"].includes(v.impact ?? ""),
  )
  expect(
    overviewSerious,
    overviewSerious.map((v) => `${v.id}: ${v.help}`).join("\n"),
  ).toEqual([])

  await page.goto("/portal/enrollment")
  const eligibleResults = await new AxeBuilder({ page })
    .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"])
    .analyze()
  const eligibleSerious = eligibleResults.violations.filter((v) =>
    ["critical", "serious"].includes(v.impact ?? ""),
  )
  expect(
    eligibleSerious,
    eligibleSerious.map((v) => `${v.id}: ${v.help}`).join("\n"),
  ).toEqual([])
})

test("accessibility — 200% zoom keeps the Enrollment page usable (no horizontal scroll, content stays reachable)", async ({
  page,
  request,
}) => {
  await authenticateViaApi(page, request, "student")
  await page.setViewportSize({ width: 640, height: 480 })
  await page.goto("/portal/enrollment")

  await expect(
    page.getByRole("heading", { name: "Select your subjects" }),
  ).toBeVisible()

  const hasHorizontalOverflow = await page.evaluate(
    () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
  )
  expect(hasHorizontalOverflow).toBe(false)
})

test("accessibility — prefers-reduced-motion suppresses the motion library's JS-driven transforms", async ({
  page,
  request,
}) => {
  await page.emulateMedia({ reducedMotion: "reduce" })
  await authenticateViaApi(page, request, "student")
  await page.goto("/portal/enrollment")

  // motion.tsx's Reveal/StaggerList/StaggerItem/FadePresence each call
  // useReducedMotion() and render a plain, motion-free wrapper when set —
  // confirmed by inspecting that no element carries a *meaningful* animation
  // duration once the eligible-subjects list (which uses StaggerList/
  // StaggerItem) has rendered. The threshold is deliberately not `> 0`: the
  // existing prefers-reduced-motion CSS blanket (globals.css) itself sets a
  // near-zero (not literally zero) duration like 0.01ms on every element —
  // a standard technique so animationend/transitionend listeners still fire
  // instead of hanging. 50ms comfortably separates "near-zero, intentional"
  // from "an actual multi-hundred-millisecond entrance animation still ran."
  await expect(
    page.getByRole("heading", { name: "Select your subjects" }),
  ).toBeVisible()

  const meaningfullyAnimatedCount = await page.evaluate(() => {
    const all = Array.from(document.querySelectorAll("*"))
    return all.filter((el) => {
      const style = window.getComputedStyle(el)
      const duration = Number.parseFloat(style.animationDuration || "0")
      return duration > 0.05 && style.animationPlayState !== "paused"
    }).length
  })
  expect(meaningfullyAnimatedCount).toBe(0)
})
