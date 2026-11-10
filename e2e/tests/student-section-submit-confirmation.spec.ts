import { expect, test } from "@playwright/test";

import { authenticateViaApi } from "../fixtures/auth";

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
};

const selectedBlock = {
  type: "enrollment_block",
  block_code: "ACC301",
  year_level: 3,
  curriculum_id: 9,
  section_plan_id: 12,
  total_units: 22.5,
  seats_remaining: 7,
  capacity: 40,
  is_selectable: true,
  reasons: [],
  preference_score: null,
  preference_reasons: [],
  subjects: [
    {
      section_id: 10041,
      subject_id: 7,
      code: "AASPIN",
      title: "AUDITING & ASSURANCE: SPECIALIZED INDUSTRY",
      units: 3,
      schedule_days: "THIRS",
      starts_at_time: "07:30:00",
      ends_at_time: "10:30:00",
      room: "3F",
      modality: "f2f",
      professor_name: null,
      capacity: 40,
      enrolled_count: 33,
      remaining_seats: 7,
    },
  ],
};

const schedulePreference = {
  type: "student-schedule-preference",
  id: null,
  student_id: 1,
  preferred_days: null,
  preferred_time_block: "any",
  preferred_time_block_label: "No Preference",
  preferred_modality: null,
  max_days_on_campus: null,
  avoid_early_first_class: false,
  notes: null,
};

const pagination = {
  links: {
    first: "http://127.0.0.1:8000/api/v1/enrollments?page=1",
    last: "http://127.0.0.1:8000/api/v1/enrollments?page=1",
    prev: null,
    next: null,
  },
  meta: {
    current_page: 1,
    from: null,
    last_page: 1,
    links: [],
    path: "http://127.0.0.1:8000/api/v1/enrollments",
    per_page: 20,
    to: null,
    total: 0,
  },
};

/**
 * Break caught: removing the selected-card Submit enrollment click handler or
 * disconnecting it from the confirmation dialog would make this visible
 * browser journey fail before any enrollment is submitted.
 */
test("selected regular section opens a confirmation popup before submission", async ({
  page,
  request,
}) => {
  await authenticateViaApi(page, request, "student");
  await page.route("**/api/v1/**", async (route) => {
    const { pathname } = new URL(route.request().url());

    if (pathname.endsWith("/auth/me")) return route.continue();

    const payload = pathname.endsWith("/academic-terms")
      ? { data: [term] }
      : pathname.endsWith("/enrollment-windows")
        ? {
            data: {
              type: "enrollment_schedule",
              academic_term_id: 2,
              status: "semester_ongoing",
              enrollment_opens_at: null,
              enrollment_closes_at: null,
              audiences: [],
              viewer: {
                audience: "year_3",
                label: "3rd Year",
                opens_at: null,
                closes_at: null,
                is_open: true,
                reason: "open",
              },
              add_drop: {
                is_open: false,
                reason: "enrollment_still_open",
                reason_message:
                  "The add/drop window opens once enrollment closes for this term.",
                opens_at: null,
                closes_at: null,
              },
            },
          }
        : pathname.endsWith("/student-schedule-preferences")
          ? { data: schedulePreference }
          : pathname.endsWith("/eligible-subjects")
            ? { data: [] }
            : pathname.endsWith("/enrollment-blocks")
              ? { data: [selectedBlock] }
              : pathname.endsWith("/enrollments")
                ? { data: [], ...pagination }
                : null;

    if (payload !== null) return route.fulfill({ json: payload });

    return route.continue();
  });

  await page.goto("/portal/enrollment");
  const section = page.getByRole("article", { name: "ACC301 section" });
  await expect(section).toBeVisible();
  await section.getByRole("button", { name: "Choose ACC301" }).click();

  await page.getByRole("button", { name: "Submit enrollment" }).click();

  const confirmation = page.getByRole("alertdialog");
  await expect(confirmation).toBeVisible();
  await expect(
    confirmation.getByRole("heading", {
      name: "Confirm enrollment submission",
    }),
  ).toBeVisible();
});
