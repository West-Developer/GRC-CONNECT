// Captures the screenshots the User Manual and Guide shows.
//
//   node scripts/user-guide/capture.mjs            # every shot in shots.config.mjs
//   node scripts/user-guide/capture.mjs login      # only the named shot ids
//
// Reads GUIDE_TOKENS (role -> bearer token, see README.md), signs in as that
// role by placing the token in local storage, and drives the REAL portal on a
// scratch database. Each shot is written to public/user-guide/<id>.jpg and its
// size, alt text and numbered markers are merged into
// src/features/lib/user-guide/shots.json.
import { launch, newContext, BASE, readTokens, settle } from "./browser.mjs"
import { shots } from "./shots.config.mjs"
import { createShooter } from "./shoot.mjs"

const wanted = process.argv.slice(2)
const selected = wanted.length
  ? shots.filter((s) => wanted.includes(s.id))
  : shots
if (wanted.length && selected.length !== wanted.length) {
  const known = new Set(shots.map((s) => s.id))
  throw new Error(
    `Unknown shot id(s): ${wanted.filter((id) => !known.has(id)).join(", ")}`,
  )
}

const { shoot } = createShooter()
const tokens = readTokens()
const browser = await launch()

const byContext = new Map()
async function contextFor(shot) {
  const key = `${shot.role ?? "anonymous"}|${shot.mobile ? "m" : "d"}`
  if (!byContext.has(key)) {
    const viewport = shot.mobile
      ? { width: 390, height: 844 }
      : { width: 1280, height: 800 }
    byContext.set(
      key,
      await newContext(browser, {
        token: shot.role ? tokens[shot.role] : null,
        viewport,
        mobile: Boolean(shot.mobile),
      }),
    )
  }
  return byContext.get(key)
}

for (const shot of selected) {
  const context = await contextFor(shot)
  const page = await context.newPage()
  page.setDefaultTimeout(90_000)
  const started = Date.now()

  try {
    await page.goto(`${BASE}${shot.path}`, { waitUntil: "domcontentloaded" })
    if (shot.role) {
      await settle(page)
    } else {
      await page.waitForSelector("h1", { timeout: 60_000 }).catch(() => {})
      await page.waitForTimeout(1200)
    }
    if (shot.prepare) {
      await shot.prepare(page)
      await page.waitForTimeout(shot.afterPrepareMs ?? 700)
    }
    await shoot(page, shot)
    console.log(`  ${((Date.now() - started) / 1000).toFixed(1)}s`)
  } catch (error) {
    console.error(`FAILED ${shot.id}: ${error.message}`)
  } finally {
    await page.close()
  }
}

await browser.close()
console.log("done")
