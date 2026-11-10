import { defineConfig, devices } from "@playwright/test"

/**
 * Journey 12 (throttle behavior) deliberately trips the login rate limiter,
 * which routes/api.php keys per IP, not per credential. Every worker shares
 * one source IP, so it runs alone in its own serial project, positioned last,
 * so a tripped limiter can't starve the sign-ins the other journeys need.
 *
 * academic-term-archive.spec.ts archives the one seeded `semester_ongoing`
 * term — a one-way transition that block-enrollment.spec.ts and
 * enrollment.spec.ts both depend on that same term still being ongoing. It
 * runs alone in its own serial project, after `chromium`, so it can never
 * race or precede the specs that need the term to still be open.
 */
export default defineConfig({
  testDir: "./tests",
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: process.env.CI ? 2 : undefined,
  reporter: [["html", { open: "never" }], ["list"]],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? "http://localhost:3000",
    trace: "on-first-retry",
    screenshot: "only-on-failure",
    video: "retain-on-failure",
  },
  projects: [
    {
      name: "chromium",
      use: { ...devices["Desktop Chrome"] },
      testIgnore: [/throttling\.spec\.ts/, /academic-term-archive\.spec\.ts/],
    },
    {
      name: "throttle-isolated",
      use: { ...devices["Desktop Chrome"] },
      testMatch: /throttling\.spec\.ts/,
      fullyParallel: false,
      workers: 1,
      dependencies: ["chromium"],
    },
    {
      name: "archive-isolated",
      use: { ...devices["Desktop Chrome"] },
      testMatch: /academic-term-archive\.spec\.ts/,
      fullyParallel: false,
      workers: 1,
      dependencies: ["chromium", "throttle-isolated"],
    },
  ],
})
