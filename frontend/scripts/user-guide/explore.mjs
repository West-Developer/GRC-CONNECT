// Takes one raw screenshot of every sidebar page for every guide role, so the
// writer can see which pages are worth a figure. Output: GUIDE_OUT/<role>/<id>.png
import fs from "node:fs"
import path from "node:path"

import { BASE, launch, newContext, readTokens, settle } from "./browser.mjs"

const out = process.env.GUIDE_OUT
if (!out) throw new Error("Set GUIDE_OUT to an output folder.")

const only = process.argv.slice(2)
const tokens = readTokens()
const browser = await launch()
const index = {}

for (const [role, token] of Object.entries(tokens)) {
  if (only.length && !only.includes(role)) continue
  const dir = path.join(out, role)
  fs.mkdirSync(dir, { recursive: true })
  const context = await newContext(browser, { token })
  const page = await context.newPage()
  page.setDefaultTimeout(90_000)

  await page.goto(`${BASE}/portal`, { waitUntil: "domcontentloaded" })
  await settle(page, { extraMs: 1500 })
  await page.screenshot({ path: path.join(dir, "_overview.png") })

  const links = await page.$$eval(
    'nav[aria-label="Role portal navigation"] a',
    (anchors) =>
      anchors.map((a) => ({
        href: a.getAttribute("href"),
        label: a.textContent?.trim(),
      })),
  )
  index[role] = links

  for (const link of links) {
    if (!link.href || link.href === "/portal") continue
    const id = link.href.split("/").pop()
    const started = Date.now()
    await page.goto(`${BASE}${link.href}`, { waitUntil: "domcontentloaded" })
    await settle(page)
    await page.screenshot({ path: path.join(dir, `${id}.png`) })
    console.log(`${role} ${id} ${((Date.now() - started) / 1000).toFixed(1)}s`)
  }
  await context.close()
}

fs.writeFileSync(path.join(out, "index.json"), JSON.stringify(index, null, 2))
await browser.close()
console.log("done")
