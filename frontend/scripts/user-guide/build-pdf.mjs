// Prints the User Manual and Guide page to PDF files in public/downloads/.
//
//   node scripts/user-guide/build-pdf.mjs             # the complete PDF and one per role
//   node scripts/user-guide/build-pdf.mjs student     # only the named role (or "all")
//
// The PDF is the page itself, printed with the print stylesheet in
// src/app/user-guide/guide.css, so the page and the PDF never disagree. Run it
// again whenever the content or the screenshots change.
//
// GUIDE_BASE is where the frontend is served (default http://localhost:3000).
// The guide page needs no API and no sign-in.
import fs from "node:fs"
import path from "node:path"
import { fileURLToPath } from "node:url"

import { chromium } from "playwright"

const here = path.dirname(fileURLToPath(import.meta.url))
const outDir = path.resolve(here, "../../public/downloads")
const BASE = process.env.GUIDE_BASE ?? "http://localhost:3000"
const FILE_BASE = "grc-enrollment-system-user-manual-and-guide"

fs.mkdirSync(outDir, { recursive: true })
const wanted = process.argv.slice(2)

const browser = await chromium.launch({ headless: true })
const context = await browser.newContext({
  locale: "en-PH",
  timezoneId: "Asia/Manila",
})

async function open(url) {
  const page = await context.newPage()
  page.setDefaultTimeout(120_000)
  await page.emulateMedia({ media: "print" })
  await page.goto(`${BASE}${url}`, { waitUntil: "domcontentloaded" })
  await page.waitForSelector("article.guide-chapter")
  return page
}

// Which roles exist is read from the page's own contents list, so this script
// never has to repeat the list.
const probe = await open("/user-guide")
const roles = await probe.$$eval(
  '.guide-contents__row > a[href*="?role="]',
  (links) => links.map((a) => new URL(a.href).searchParams.get("role")),
)
const labels = Object.fromEntries(
  await probe.$$eval('.guide-contents__row > a[href*="?role="]', (links) =>
    links.map((a) => [
      new URL(a.href).searchParams.get("role"),
      a.querySelector(".guide-contents__title")?.textContent?.trim() ?? "",
    ]),
  ),
)
await probe.close()

const targets = [
  {
    key: "all",
    file: `${FILE_BASE}.pdf`,
    title: "GRC Enrollment System user manual and guide",
  },
  ...roles.map((role) => ({
    key: role,
    file: `${FILE_BASE}-${role.replace(/_/g, "-")}.pdf`,
    title: `GRC Enrollment System user manual and guide: ${labels[role]}`,
  })),
]

for (const target of targets) {
  if (wanted.length && !wanted.includes(target.key)) continue
  const started = Date.now()
  const page = await open(`/user-guide?role=${target.key}`)
  await page.evaluate(async (title) => {
    document.title = title
    // Next re-applies the page metadata after a render; keep ours for the PDF.
    new MutationObserver(() => {
      if (document.title !== title) document.title = title
    }).observe(document.head, {
      subtree: true,
      childList: true,
      characterData: true,
    })
    for (const image of document.images) image.loading = "eager"
    await document.fonts.ready
    await Promise.all(
      [...document.images].map((image) =>
        image.complete ? undefined : image.decode().catch(() => undefined),
      ),
    )
  }, target.title)
  await page.waitForTimeout(800)

  const file = path.join(outDir, target.file)
  await page.pdf({
    path: file,
    format: "A4",
    printBackground: true,
    preferCSSPageSize: true,
    outline: true,
    tagged: true,
  })
  await page.close()
  const size = fs.statSync(file).size
  console.log(
    `${target.file} ${(size / 1024 / 1024).toFixed(1)} MB ${((Date.now() - started) / 1000).toFixed(0)}s`,
  )
}

await browser.close()
