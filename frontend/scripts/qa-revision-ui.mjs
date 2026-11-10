// UI smoke verification with synthetic API fixtures; never calls the live API.
import { chromium } from "playwright"
import { mkdir, writeFile } from "node:fs/promises"
import { tmpdir } from "node:os"
import { join } from "node:path"
import { fileURLToPath } from "node:url"
import { createServer } from "vite"
import react from "@vitejs/plugin-react"
import tailwind from "@tailwindcss/postcss"

const base = "http://127.0.0.1:3218"
const output = join(tmpdir(), "grc-qa-revision-ui")
await mkdir(output, { recursive: true })
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
const subject = {
  section_id: 6,
  section_code: "B",
  subject_id: 2,
  subject_code: "CS102",
  subject_title: "Data Structures",
  paired_subject_id: null,
  units: 3,
  schedule_days: "TTh",
  starts_at_time: "08:00:00",
  ends_at_time: "09:30:00",
  room: "R1",
  modality: "f2f",
  professor_name: null,
  status: "selected",
  status_label: "Selected",
}
const enrollment = {
  type: "enrollment",
  id: 21,
  student_id: 8,
  student_number: "SYNTHETIC-QA",
  student_name: null,
  student_year_level: 1,
  student_financial_status: null,
  student_financial_status_label: null,
  student_enrollment_category: "irregular",
  is_irregular: true,
  academic_term_id: 2,
  status: "pending_student_review",
  status_label: "Awaiting Student Review",
  total_units: 3,
  requires_overload_approval: false,
  regular_unit_maximum: null,
  unit_maximum: null,
  program_head_comment: null,
  submitted_at: null,
  program_head_decided_at: null,
  registrar_decided_at: null,
  payment_confirmed_at: null,
  enrolled_at: null,
  subjects: [subject],
  revisions: [],
  queue_ticket: null,
  assessment: null,
}
const eligible = {
  type: "eligible_subject",
  subject_id: 3,
  code: "CS103",
  title: "Algorithms",
  units: 3,
  paired_subject_id: null,
  year_level: 1,
  semester: "1st",
  is_required: true,
  is_eligible: true,
  reasons: [{ code: "eligible", message: "All requirements are met." }],
  preference_score: null,
  preference_reasons: [],
  available_sections: [
    {
      type: "section",
      id: 7,
      academic_term_id: 2,
      subject_id: 3,
      section_code: "C",
      professor_id: null,
      professor_name: null,
      schedule_days: "MWF",
      starts_at_time: "10:00:00",
      ends_at_time: "11:00:00",
      room: "R2",
      capacity: 40,
      capacity_source: "plan",
      viability_threshold: null,
      enrolled_count: 0,
      remaining_seats: 40,
      is_block_exclusive: null,
      status: "published",
      status_label: "Published",
      college: "ccs",
      is_own_department: true,
      subject_code: "CS103",
      subject_title: "Algorithms",
    },
  ],
}
const user = {
  type: "user",
  id: 4,
  name: "Synthetic QA Student",
  email: "synthetic@grc.test",
  role: "student",
  role_label: "Student",
  status: "active",
  privacy_notice: {
    version: "qa",
    accepted_version: "qa",
    accepted_at: "2026-10-28T00:00:00Z",
    requires_acceptance: false,
  },
}
const viewer = {
  audience: "irregular",
  label: "Irregular Students",
  opens_at: null,
  closes_at: null,
  is_open: true,
  reason: "open",
}
const pagination = {
  links: { first: base, last: base, prev: null, next: null },
  meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
}
const project = fileURLToPath(new URL("..", import.meta.url))
const harness = join(project, ".next", "qa-ui")
await mkdir(harness, { recursive: true })
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
import { StudentProposeSubjectsDialog } from '@/features/components/portal/student-propose-subjects-dialog';
import { setAuthTokenProvider } from '@/features/services/api-client';
import '@/app/globals.css';
setAuthTokenProvider(() => 'synthetic-not-a-credential');
const queryClient = new QueryClient({defaultOptions:{queries:{retry:false}}});
createRoot(document.getElementById('root')).render(<QueryClientProvider client={queryClient}><AuthContext.Provider value={{session:{userId:'4',displayName:'Synthetic QA Student',role:'student'},status:'authenticated'}}><StudentProposeSubjectsDialog enrollment={${JSON.stringify(enrollment)}} open onOpenChange={()=>{}} /></AuthContext.Provider></QueryClientProvider>);
`,
)
const server = await createServer({
  configFile: false,
  root: harness,
  plugins: [react()],
  resolve: { alias: { "@": join(project, "src") } },
  css: { postcss: { plugins: [tailwind()] } },
  define: { "process.env.NEXT_PUBLIC_API_BASE_URL": JSON.stringify(base) },
  server: {
    host: "127.0.0.1",
    port: 3218,
    strictPort: true,
    fs: { allow: [project] },
  },
})
await server.listen()
const browser = await chromium.launch({ headless: true })
const results = []
try {
  for (const viewport of [
    { width: 1440, height: 1000 },
    { width: 390, height: 844 },
  ]) {
    const context = await browser.newContext({
      viewport,
      reducedMotion: "reduce",
    })
    await context.addInitScript(() =>
      localStorage.setItem("grc.auth-token.v1", "synthetic-not-a-credential"),
    )
    const page = await context.newPage()
    const errors = []
    page.on("pageerror", (error) => errors.push(error.message))
    await context.route("**/api/v1/**", async (route) => {
      const url = new URL(route.request().url())
      let body
      if (url.pathname.endsWith("/auth/me")) body = { data: user }
      else if (url.pathname.endsWith("/academic-terms")) body = { data: [term] }
      else if (url.pathname.endsWith("/enrollment-schedule"))
        body = {
          data: {
            type: "enrollment_schedule",
            academic_term_id: 2,
            status: "semester_ongoing",
            enrollment_opens_at: null,
            enrollment_closes_at: null,
            audiences: [viewer],
            viewer,
            add_drop: {
              is_open: false,
              reason: "enrollment_still_open",
              reason_message: "Enrollment is open.",
              opens_at: null,
              closes_at: null,
            },
          },
        }
      else if (url.pathname.endsWith("/eligible-subjects"))
        body = { data: url.searchParams.has("enrollment_id") ? [eligible] : [] }
      else if (url.pathname.endsWith("/enrollments"))
        body = { data: [enrollment], ...pagination }
      else body = { data: [], ...pagination }
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        headers: {
          "access-control-allow-origin": "*",
          "access-control-allow-headers": "*",
        },
        body: JSON.stringify(body),
      })
    })
    try {
      await page.goto(base, { waitUntil: "domcontentloaded", timeout: 60000 })
      const dialog = page.getByRole("dialog", {
        name: "Propose different subjects",
      })
      await dialog
        .getByRole("combobox", { name: "Add a subject or section" })
        .click()
      await page.getByRole("option", { name: /CS103/ }).click()
      await dialog.getByText("6", { exact: true }).waitFor()
      await page.screenshot({
        path: join(output, `${viewport.width}-table.png`),
        fullPage: true,
      })
      await dialog.getByRole("radio", { name: "Calendar timetable" }).click()
      await dialog.getByText("CS103", { exact: true }).first().waitFor()
      await page.screenshot({
        path: join(output, `${viewport.width}-calendar.png`),
        fullPage: true,
      })
      for (let index = 0; index < 8; index++) {
        await page.keyboard.press("Tab")
        const trapped = await page.evaluate(
          () => !!document.activeElement?.closest('[role="dialog"]'),
        )
        if (!trapped) throw new Error("Focus escaped the proposal dialog")
      }
      if (
        await page.evaluate(
          () => document.documentElement.scrollWidth > window.innerWidth,
        )
      )
        throw new Error("Page overflows viewport")
      results.push({ viewport, passed: true, pageErrors: errors })
    } catch (error) {
      await writeFile(
        join(output, `${viewport.width}-failure.txt`),
        await page.locator("body").innerText(),
      )
      await page
        .screenshot({
          path: join(output, `${viewport.width}-failure.png`),
          fullPage: true,
        })
        .catch(() => {})
      results.push({
        viewport,
        passed: false,
        error: error.message,
        pageErrors: errors,
      })
    }
    await context.close()
  }
} finally {
  await browser.close()
  await server.close()
}
await writeFile(join(output, "results.json"), JSON.stringify(results, null, 2))
console.log(JSON.stringify({ output, results }, null, 2))
if (results.some((result) => !result.passed || result.pageErrors.length))
  process.exitCode = 1
