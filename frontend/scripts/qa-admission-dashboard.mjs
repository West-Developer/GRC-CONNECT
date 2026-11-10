// Responsive UI verification using synthetic Admission data; never uses the live API.
import assert from "node:assert/strict"
import { mkdir, writeFile } from "node:fs/promises"
import { tmpdir } from "node:os"
import { join } from "node:path"
import { fileURLToPath } from "node:url"
import { chromium } from "playwright"
import { createServer } from "vite"
import react from "@vitejs/plugin-react"
import tailwind from "@tailwindcss/postcss"

const project = fileURLToPath(new URL("..", import.meta.url))
const harness = join(project, ".next", "qa-admission-dashboard")
const output = join(tmpdir(), "grc-qa-admission-dashboard")
const base = "http://127.0.0.1:3219"
await mkdir(harness, { recursive: true })
await mkdir(output, { recursive: true })
// Next's framework adapters are replaced only in this standalone layout harness.
await writeFile(
  join(harness, "next-image.jsx"),
  `import React from 'react'; export default function Image({priority, ...props}) { return <img {...props} /> }`,
)
await writeFile(
  join(harness, "next-link.jsx"),
  `import React from 'react'; export default function Link({prefetch, ...props}) { return <a {...props} /> }`,
)

const groupCounts = {
  enrolled: 0,
  in_progress: 0,
  not_yet_done: 1234,
  not_enrolled: 0,
}
const overview = {
  type: "enrollment_status_overview",
  academic_term_id: 2,
  total_students: 4936,
  groups: { ...groupCounts, not_yet_done: 4936 },
  steps: {},
  departments: [
    ["ccs", "College of Computer Studies"],
    ["coe", "College of Education"],
    ["coa", "College of Accountancy"],
    ["cbae", "College of Business Administration and Entrepreneurship"],
  ].map(([department, label]) => ({
    department,
    label,
    total: 1234,
    groups: groupCounts,
    steps: {},
  })),
}
const term = {
  type: "academic-term",
  id: 2,
  school_year: "2026-2027",
  semester: "1st",
  starts_at: null,
  ends_at: null,
  enrollment_opens_at: null,
  enrollment_closes_at: null,
  add_drop_deadline_at: null,
  grading_deadline_at: null,
  status: "semester_ongoing",
  status_label: "Semester Ongoing",
}
await writeFile(
  join(harness, "index.html"),
  '<html lang="en"><head><meta name="viewport" content="width=device-width, initial-scale=1" /></head><body><div id="root"></div><script type="module" src="/entry.jsx"></script></body></html>',
)
await writeFile(
  join(harness, "entry.jsx"),
  `
import React from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthContext } from '@/features/auth/auth-context-value';
import { EnrollmentDashboardWorkspace } from '@/features/components/portal/enrollment-dashboard-workspace';
import { GrcConnectPage } from '@/features/components/pages/portal-overview-page';
import { setAuthTokenProvider } from '@/features/services/api-client';
import '@/app/globals.css';
setAuthTokenProvider(() => 'synthetic-not-a-credential');
const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
const session = {userId:'4', displayName:'Synthetic Admission Staff', role:'admission_staff', signedInAt:'2026-11-04T00:00:00Z'};
createRoot(document.getElementById('root')).render(<QueryClientProvider client={client}><AuthContext.Provider value={{session, status:'authenticated',storageAvailable:true}}><div className="portal-app"><aside className="portal-sidebar">Synthetic Admission Staff</aside><div className="portal-main-column"><div className="portal-content">{location.search.includes('home') ? <GrcConnectPage /> : <EnrollmentDashboardWorkspace />}</div></div></div></AuthContext.Provider></QueryClientProvider>);
`,
)

const server = await createServer({
  configFile: false,
  root: harness,
  publicDir: join(project, "public"),
  plugins: [react()],
  resolve: {
    alias: {
      "@": join(project, "src"),
      "next/image": join(harness, "next-image.jsx"),
      "next/link": join(harness, "next-link.jsx"),
    },
  },
  css: { postcss: { plugins: [tailwind()] } },
  define: { "process.env.NEXT_PUBLIC_API_BASE_URL": JSON.stringify(base) },
  server: {
    host: "127.0.0.1",
    port: 3219,
    strictPort: true,
    fs: { allow: [project] },
  },
})
await server.listen()
const browser = await chromium.launch({ headless: true })
const results = []
try {
  for (const width of [390, 768, 1024, 1280, 1440, 1920]) {
    const context = await browser.newContext({
      viewport: { width, height: 1000 },
      reducedMotion: "reduce",
    })
    await context.route("**/api/v1/**", async (route) => {
      const path = new URL(route.request().url()).pathname
      const data = path.endsWith("academic-terms")
        ? [term]
        : path.endsWith("enrollment-status")
          ? overview
          : path.endsWith("health")
            ? {
                type: "service-health",
                service: "grc-enrollment-api",
                status: "ok",
                api_version: "v1",
                generated_at: "2026-11-04T00:00:00Z",
              }
            : []
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({ data }),
      })
    })
    const page = await context.newPage()
    const errors = []
    page.on("pageerror", (error) => errors.push(error.message))
    await page.goto(base, { waitUntil: "domcontentloaded" })
    await page
      .getByRole("button", { name: /Not yet done: 4,936 students/ })
      .waitFor()
    await page.screenshot({
      path: join(output, `${width}-dashboard.png`),
      fullPage: true,
    })
    const layout = await page.evaluate(() => ({
      overflow: document.documentElement.scrollWidth - window.innerWidth,
      clippedDepartmentControls: Array.from(
        document.querySelectorAll("section[aria-label] button"),
      )
        .filter((element) => element.scrollWidth > element.clientWidth + 1)
        .map((element) => ({
          label: element.getAttribute("aria-label") ?? element.textContent,
          width: element.clientWidth,
          contentWidth: element.scrollWidth,
        })),
    }))
    await page.goto(`${base}?home`, { waitUntil: "domcontentloaded" })
    await page
      .getByRole("heading", { name: "GRC Connect", exact: true })
      .waitFor()
    await page
      .getByText("Enrollment portal available", { exact: true })
      .waitFor()
    await page.screenshot({
      path: join(output, `${width}-home.png`),
      fullPage: true,
    })
    const homeLayout = await page.evaluate(() => {
      const grid = document.querySelector(".portal-module-grid")
      const cards = Array.from(grid.querySelectorAll(".portal-module-card"))
      const first = cards[0].getBoundingClientRect()
      const last = cards.at(-1).getBoundingClientRect()
      const sameRow = Math.abs(first.top - last.top) < 1
      const titleTops = cards.map(
        (card) =>
          card.querySelector('[data-slot="card-title"]').getBoundingClientRect()
            .top,
      )
      return {
        homeOverflow: document.documentElement.scrollWidth - window.innerWidth,
        unusedGridWidth: sameRow
          ? grid.getBoundingClientRect().right - last.right
          : 0,
        titleTopDifference: sameRow
          ? Math.max(...titleTops) - Math.min(...titleTops)
          : 0,
      }
    })
    results.push({ width, ...layout, ...homeLayout, errors })
    await context.close()
  }
} finally {
  await browser.close()
  await server.close()
}
await writeFile(join(output, "results.json"), JSON.stringify(results, null, 2))
console.log(JSON.stringify({ output, results }, null, 2))
assert(
  results.every(
    (result) =>
      result.overflow <= 0 &&
      result.homeOverflow <= 0 &&
      result.unusedGridWidth <= 1 &&
      result.titleTopDifference <= 1 &&
      result.clippedDepartmentControls.length === 0 &&
      result.errors.length === 0,
  ),
  "Admission dashboard contains clipped or overflowing controls",
)
