import { screen } from "@testing-library/react"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { EnrollmentReviewDialog } from "@/features/components/portal/enrollment-review-dialog"
import { formatYearLevelOrdinal } from "@/features/lib/curriculum-ordinal"
import type { Enrollment } from "@/features/schemas/enrollment-schema"
import { renderWithSession } from "@/tests/render-app"

describe("EnrollmentReviewDialog & formatYearLevelOrdinal", () => {
  beforeEach(() => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockImplementation((input: RequestInfo | URL) => {
        const url =
          typeof input === "string"
            ? input
            : input instanceof URL
              ? input.toString()
              : input.url
        if (url.includes("/api/v1/sections") || url.includes("/api/v1/subjects")) {
          return Promise.resolve(new Response(JSON.stringify({ data: [] })))
        }
        return Promise.resolve(new Response(JSON.stringify({ data: [] })))
      }),
    )
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })
  it("formats year levels as institutional ordinals", () => {
    expect(formatYearLevelOrdinal(1)).toBe("1ST YEAR")
    expect(formatYearLevelOrdinal(2)).toBe("2ND YEAR")
    expect(formatYearLevelOrdinal(3)).toBe("3RD YEAR")
    expect(formatYearLevelOrdinal(4)).toBe("4TH YEAR")
    expect(formatYearLevelOrdinal(null)).toBe("—")
    expect(formatYearLevelOrdinal(undefined)).toBe("—")
  })

  it("renders 1ST YEAR for a student with year_level 1", () => {
    const enrollment: Enrollment = {
      type: "enrollment",
      id: 30333,
      student_id: 1,
      student_number: "2026-06-01090",
      student_name: "Natividad A. Velasco",
      student_year_level: 1,
      student_financial_status: null,
      student_financial_status_label: null,
      academic_term_id: 1,
      status: "pending_registrar_approval",
      status_label: "Pending Registrar Approval",
      total_units: 30.5,
      requires_overload_approval: false,
      program_head_comment: null,
      submitted_at: "2026-09-08T00:00:00Z",
      program_head_decided_at: null,
      registrar_decided_at: null,
      payment_confirmed_at: null,
      enrolled_at: null,
      subjects: [],
      revisions: [],
      queue_ticket: null,
      assessment: null,
    }

    renderWithSession(
      <EnrollmentReviewDialog
        enrollment={enrollment}
        onOpenChange={vi.fn()}
      />,
    )

    expect(screen.getByText("1ST YEAR")).toBeInTheDocument()
    expect(screen.queryByText("Year 1")).not.toBeInTheDocument()
  })

  it("formats section time in 12-hour AM/PM format in the table view", async () => {
    const enrollment: Enrollment = {
      type: "enrollment",
      id: 30626,
      student_id: 2,
      student_number: "TEST-IRREG-Y2-03",
      student_name: "Dominic Sean V. Navarro",
      student_year_level: 3,
      student_financial_status: null,
      student_financial_status_label: null,
      academic_term_id: 48,
      status: "pending_program_head_approval",
      status_label: "Pending Program Head Approval",
      total_units: 4,
      requires_overload_approval: false,
      program_head_comment: null,
      submitted_at: "2026-10-26T00:00:00Z",
      program_head_decided_at: null,
      registrar_decided_at: null,
      payment_confirmed_at: null,
      enrolled_at: null,
      subjects: [
        {
          section_id: 17654,
          section_code: "IT301",
          subject_id: 101,
          subject_code: "ARTAPP",
          paired_subject_id: null,
          subject_title: "Art Appreciation",
          units: 3,
          schedule_days: "MON",
          starts_at_time: "07:30:00",
          ends_at_time: "10:30:00",
          room: "3A",
          status: "selected",
          status_label: "Selected",
        },
        {
          section_id: 17656,
          section_code: "IT301",
          subject_id: 102,
          subject_code: "BMCL",
          paired_subject_id: null,
          subject_title: "Basic Mobile Computing LAB",
          units: 1,
          schedule_days: "MON",
          starts_at_time: "13:00:00",
          ends_at_time: "16:00:00",
          room: "LAB 1",
          status: "selected",
          status_label: "Selected",
        },
      ],
      revisions: [],
      queue_ticket: null,
      assessment: null,
    }

    renderWithSession(
      <EnrollmentReviewDialog
        enrollment={enrollment}
        onOpenChange={vi.fn()}
      />,
    )

    const morningSlots = await screen.findAllByText("7:30 AM–10:30 AM")
    expect(morningSlots.length).toBeGreaterThan(0)

    const afternoonSlots = await screen.findAllByText("1:00 PM–4:00 PM")
    expect(afternoonSlots.length).toBeGreaterThan(0)

    expect(screen.queryByText("07:30–10:30")).not.toBeInTheDocument()
    expect(screen.queryByText("13:00–16:00")).not.toBeInTheDocument()
  })
})
