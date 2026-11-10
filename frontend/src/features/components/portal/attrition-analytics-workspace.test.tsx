import { screen, waitFor } from "@testing-library/react"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { AttritionAnalyticsWorkspace } from "@/features/components/portal/attrition-analytics-workspace"
import { renderWithSession } from "@/tests/render-app"

const terms = {
  data: [
    {
      type: "academic-term",
      id: 1,
      school_year: "2024-2025",
      semester: "1st",
      starts_at: null,
      ends_at: null,
      enrollment_opens_at: null,
      enrollment_closes_at: null,
      add_drop_opens_at: null,
      add_drop_deadline_at: null,
      grading_deadline_at: null,
      status: "semester_closed",
      status_label: "Semester Closed",
    },
    {
      type: "academic-term",
      id: 2,
      school_year: "2024-2025",
      semester: "2nd",
      starts_at: null,
      ends_at: null,
      enrollment_opens_at: null,
      enrollment_closes_at: null,
      add_drop_opens_at: null,
      add_drop_deadline_at: null,
      grading_deadline_at: null,
      status: "semester_ongoing",
      status_label: "Semester Ongoing",
    },
  ],
} as const

const programs = {
  data: [
    {
      type: "program",
      id: 1,
      code: "BSCS",
      name: "Bachelor of Science in Computer Science",
      status: "active",
      status_label: "Active",
    },
  ],
} as const

const attritionReport = {
  data: {
    type: "attrition_report",
    baseline_term: {
      id: 1,
      school_year: "2024-2025",
      semester: "1st",
    },
    comparison_term: {
      id: 2,
      school_year: "2024-2025",
      semester: "2nd",
    },
    generated_at: "2026-09-23T00:00:00Z",
    summary: {
      baseline_count: 100,
      retained_count: 85,
      attrited_count: 15,
      attrition_rate: 15.0,
      undecided_count: 7,
      graduated_count: 2,
    },
    groups: {
      colleges: [
        {
          college: "ccs",
          baseline_count: 100,
          retained_count: 85,
          attrited_count: 15,
          attrition_rate: 15.0,
        },
      ],
      programs: [
        {
          college: "ccs",
          program_id: 1,
          program_code: "BSCS",
          program_name: "Bachelor of Science in Computer Science",
          baseline_count: 100,
          retained_count: 85,
          attrited_count: 15,
          attrition_rate: 15.0,
        },
      ],
      year_levels: [
        {
          year_level: 1,
          baseline_count: 50,
          retained_count: 45,
          attrited_count: 5,
          attrition_rate: 10.0,
        },
      ],
    },
  },
} as const

const chairAnalyticsSummary = {
  data: {
    type: "program_chair_analytics_summary",
    academic_term_id: 2,
    college: "all",
    official_enrolled_count: 85,
    year_level: null,
    enrollment_status_counts: { enrolled: 85 },
    grade_status_counts: { passed: 80, failed: 5 },
    retention_breakdown: [],
    year_over_year: [
      {
        school_year: "2024-2025",
        semester: "1st",
        enrollee_count: 100,
        stopped_count: 0,
        attrition_rate: 0,
      },
      {
        school_year: "2024-2025",
        semester: "2nd",
        enrollee_count: 85,
        stopped_count: 15,
        attrition_rate: 15.0,
      },
    ],
  },
} as const

describe("AttritionAnalyticsWorkspace", () => {
  const fetchMock = vi.fn()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    fetchMock.mockImplementation((input: RequestInfo | URL) => {
      const url = String(input)
      if (url.includes("/api/v1/academic-terms")) {
        return Promise.resolve(new Response(JSON.stringify(terms)))
      }
      if (url.includes("/api/v1/programs")) {
        return Promise.resolve(new Response(JSON.stringify(programs)))
      }
      if (url.includes("/api/v1/analytics/attrition")) {
        return Promise.resolve(new Response(JSON.stringify(attritionReport)))
      }
      if (url.includes("/api/v1/dashboards/program-chair-analytics-summary")) {
        return Promise.resolve(
          new Response(JSON.stringify(chairAnalyticsSummary)),
        )
      }
      return Promise.reject(new Error(`Unhandled request: ${url}`))
    })
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it("renders attrition analytics with filters, stopped students trend chart, and cohort breakdown", async () => {
    renderWithSession(<AttritionAnalyticsWorkspace />, {
      session: {
        userId: "1",
        displayName: "Registrar Head",
        role: "registrar_head",
        signedInAt: "2026-08-12T00:00:00Z",
      },
    })

    expect(
      await screen.findByRole("heading", { name: "Attrition analytics" }),
    ).toBeInTheDocument()

    expect(
      screen.getByRole("heading", { name: "Attrition & retention filters" }),
    ).toBeInTheDocument()

    await waitFor(() => {
      expect(
        screen.getByRole("heading", { name: "Stopped Students Trend" }),
      ).toBeInTheDocument()
    })

    expect(
      screen.getByRole("heading", { name: "Program Retention & Attrition" }),
    ).toBeInTheDocument()

    expect(
      screen.getByRole("heading", { name: /Program cohorts/ }),
    ).toBeInTheDocument()
  })

  it("counts only students who will not continue and says who is left out", async () => {
    renderWithSession(<AttritionAnalyticsWorkspace />, {
      session: {
        userId: "1",
        displayName: "Registrar Head",
        role: "registrar_head",
        signedInAt: "2026-08-12T00:00:00Z",
      },
    })

    expect(
      await screen.findByRole("heading", { name: "Will not continue" }),
    ).toBeInTheDocument()
    expect(screen.queryByText("Did not enroll")).not.toBeInTheDocument()
    expect(
      screen.getByText(
        /7 student\(s\) from this cohort are still deciding or enrolling, so they are not counted as attrition yet/,
      ),
    ).toBeInTheDocument()
    expect(
      screen.getByText(
        /Students still enrolling and graduates are not counted/,
      ),
    ).toBeInTheDocument()
  })

  it("has no detectable accessibility violations once loaded", async () => {
    const { container } = renderWithSession(<AttritionAnalyticsWorkspace />, {
      session: {
        userId: "1",
        displayName: "Registrar Head",
        role: "registrar_head",
        signedInAt: "2026-08-12T00:00:00Z",
      },
    })

    await screen.findByRole("heading", { name: "Attrition analytics" })
    await waitFor(() => {
      expect(
        screen.getByRole("heading", { name: "Stopped Students Trend" }),
      ).toBeInTheDocument()
    })

    const results = await axe(container)
    expect(results).toHaveNoViolations()
  })
})
