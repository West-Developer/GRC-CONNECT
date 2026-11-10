// Shared browser helpers for the User Manual and Guide tools.
//
// These tools drive the REAL portal against a SCRATCH database (never the
// owner's dev or production data). See `README.md` in this folder.
import fs from "node:fs"

import { chromium } from "playwright"

export const BASE = process.env.GUIDE_BASE ?? "http://localhost:3000"
// The frontend is built against this API origin (frontend/.env.local); every
// call to it is re-pointed at the private scratch backend below.
export const API_ORIGIN =
  process.env.GUIDE_API_ORIGIN ?? "http://127.0.0.1:8000"
export const API_TARGET =
  process.env.GUIDE_API_TARGET ?? "http://127.0.0.1:8001"
export const TOKEN_KEY = "grc.auth-token.v1"

export function readTokens() {
  const file = process.env.GUIDE_TOKENS
  if (!file) {
    throw new Error("Set GUIDE_TOKENS to the JSON file of per-role tokens.")
  }
  return JSON.parse(fs.readFileSync(file, "utf8"))
}

export async function launch() {
  return chromium.launch({ headless: true })
}

/** A fresh browser context signed in as one role (or anonymous). */
export async function newContext(
  browser,
  { token = null, viewport, mobile = false } = {},
) {
  const context = await browser.newContext({
    viewport: viewport ?? { width: 1280, height: 800 },
    deviceScaleFactor: mobile ? 2 : 1,
    isMobile: mobile,
    hasTouch: mobile,
    locale: "en-PH",
    timezoneId: "Asia/Manila",
    reducedMotion: "reduce",
  })

  if (token) {
    await context.addInitScript(
      ([key, value]) => {
        try {
          window.localStorage.setItem(key, value)
        } catch {
          /* storage unavailable */
        }
      },
      [TOKEN_KEY, token],
    )
  }

  // The guide's own link in the top bar is not part of the pictures: pictures
  // taken before it existed would otherwise differ from the ones taken after.
  await context.addInitScript(() => {
    const hide = () => {
      const style = document.createElement("style")
      style.textContent = ".portal-topbar__help{display:none!important}"
      document.documentElement.appendChild(style)
    }
    if (document.documentElement) hide()
    else document.addEventListener("DOMContentLoaded", hide)
  })

  const cors = {
    "access-control-allow-origin": BASE,
    "access-control-allow-headers": "*",
    "access-control-allow-methods": "*",
  }
  await context.route(`${API_ORIGIN}/**`, async (route) => {
    const request = route.request()
    if (request.method() === "OPTIONS") {
      return route.fulfill({ status: 204, headers: cors })
    }
    try {
      const response = await route.fetch({
        url: request.url().replace(API_ORIGIN, API_TARGET),
        timeout: 90_000,
      })
      return route.fulfill({
        response,
        headers: { ...response.headers(), ...cors },
      })
    } catch {
      return route.abort()
    }
  })

  // Third-party widgets are not part of the guide and can hang offline.
  await context.route(/accounts\.google\.com|gstatic\.com\/.*gsi/, (route) =>
    route.abort(),
  )

  return context
}

/**
 * Waits until the portal workspace has real content: the shell is up, no
 * skeleton or spinner is left, and the entrance animation has finished.
 */
export async function settle(page, { extraMs = 900 } = {}) {
  await page
    .waitForSelector("#portal-content", { timeout: 90_000 })
    .catch(() => {})
  await page
    .waitForFunction(
      () => {
        const content = document.querySelector("#portal-content")
        if (!content) return false
        const term = document.querySelector(".portal-sidebar__footer p")
        if (term && /loading/i.test(term.textContent ?? "")) return false
        const busy = content.querySelector(
          '[data-slot="skeleton"], [aria-busy="true"], .animate-spin',
        )
        return !busy && content.innerText.trim().length > 20
      },
      null,
      { timeout: 90_000 },
    )
    .catch(() => {})
  await page.waitForTimeout(extraMs)
}
